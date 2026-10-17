<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;

/**
 * T004 / T006 — Existencias (US2, FR-003, D-005) y alertas de stock mínimo
 * (RN-028, FR-005). Contrato: specs/006-modulo-inventario/contracts/api.md.
 */
function addStock(AssetType $type, Location $location, int $quantity): void
{
    app(RecordStockMovement::class)->handle(MovementType::Entry, $type, $quantity, null, $location, null, User::factory()->create());
}

beforeEach(function () {
    $this->category = AssetCategory::factory()->create(['name' => 'Protección personal']);
    $this->gloves = AssetType::factory()->for($this->category, 'category')->create([
        'name' => 'Guantes de rescate', 'tracking_mode' => TrackingMode::Quantity, 'min_stock' => 10,
    ]);
    $this->warehouse = Location::factory()->create(['name' => 'Bodega principal']);
    $this->engine = Location::factory()->create(['name' => 'Máquina 1']);
});

describe('GET /api/inventory/stock', function () {
    it('US2-1: filas tipo × ubicación con total del tipo, mínimo y alerta', function () {
        actingAsRole('consulta');
        addStock($this->gloves, $this->warehouse, 5);
        addStock($this->gloves, $this->engine, 3);

        $this->getJson("/api/inventory/stock?location_id={$this->engine->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', [
                'asset_type' => [
                    'id' => $this->gloves->id, 'name' => 'Guantes de rescate',
                    'category' => ['id' => $this->category->id, 'name' => 'Protección personal'],
                ],
                'location' => ['id' => $this->engine->id, 'name' => 'Máquina 1', 'is_active' => true],
                'quantity' => 3,
                'type_total' => 8,
                'min_stock' => 10,
                'below_min' => true,
            ])
            ->assertJsonPath('meta.total', 1);
    });

    it('US2-1: solo filas con existencia y filtros por categoría, tipo y bajo mínimo', function () {
        actingAsRole('consulta');
        $rope = AssetType::factory()->create(['name' => 'Cuerda', 'tracking_mode' => TrackingMode::Quantity, 'min_stock' => null]);
        addStock($this->gloves, $this->warehouse, 12);
        addStock($rope, $this->warehouse, 2);
        app(RecordStockMovement::class)->handle(MovementType::Transfer, $rope, 2, $this->warehouse, $this->engine, null, User::factory()->create());

        $rows = fn (string $query) => collect($this->getJson("/api/inventory/stock?{$query}")->assertOk()->json('data'))
            ->map(fn (array $row) => $row['asset_type']['name'].'@'.$row['location']['name'])->all();

        expect($rows(''))->toBe(['Cuerda@Máquina 1', 'Guantes de rescate@Bodega principal'])
            ->and($rows("asset_category_id={$this->category->id}"))->toBe(['Guantes de rescate@Bodega principal'])
            ->and($rows("asset_type_id={$rope->id}"))->toBe(['Cuerda@Máquina 1'])
            ->and($rows('below_min=1'))->toBe([]);

        app(RecordStockMovement::class)->handle(MovementType::Exit, $this->gloves, 3, $this->warehouse, null, 'Consumo', User::factory()->create());

        expect($rows('below_min=1'))->toBe(['Guantes de rescate@Bodega principal']);

        $this->getJson('/api/inventory/stock?per_page=101')->assertJsonValidationErrors('per_page');
    });

    it('FR-006: exige inventario.ver', function () {
        actingWithPermissions(['activos.ver']);

        $this->getJson('/api/inventory/stock')->assertForbidden();
        $this->getJson('/api/inventory/serialized-summary')->assertForbidden();
        $this->getJson('/api/inventory/alerts')->assertForbidden();
    });
});

describe('GET /api/inventory/serialized-summary', function () {
    it('D-005: agrupa los serializados por tipo, ubicación y estado', function () {
        actingAsRole('consulta');
        $helmet = AssetType::factory()->create(['name' => 'Casco', 'tracking_mode' => TrackingMode::Serialized]);
        Asset::factory()->count(2)->for($helmet, 'assetType')->at($this->warehouse)->create();
        Asset::factory()->for($helmet, 'assetType')->at($this->warehouse)->inMaintenance()->create();
        Asset::factory()->for($helmet, 'assetType')->assignedTo(Employee::factory()->create())->create();

        $this->getJson('/api/inventory/serialized-summary')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['asset_type' => ['id' => $helmet->id, 'name' => 'Casco'], 'location' => ['id' => $this->warehouse->id, 'name' => 'Bodega principal'], 'status' => 'available', 'count' => 2],
                ['asset_type' => ['id' => $helmet->id, 'name' => 'Casco'], 'location' => ['id' => $this->warehouse->id, 'name' => 'Bodega principal'], 'status' => 'maintenance', 'count' => 1],
                ['asset_type' => ['id' => $helmet->id, 'name' => 'Casco'], 'location' => null, 'status' => 'assigned', 'count' => 1],
            ]]);
    });
});

describe('GET /api/inventory/alerts', function () {
    it('US2-2 / RN-028: tipos por cantidad cuya existencia total está bajo el mínimo', function () {
        actingAsRole('consulta');
        addStock($this->gloves, $this->warehouse, 5);
        addStock($this->gloves, $this->engine, 3);
        $empty = AssetType::factory()->create(['name' => 'Linternas', 'tracking_mode' => TrackingMode::Quantity, 'min_stock' => 2]);
        $enough = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, 'min_stock' => 4]);
        addStock($enough, $this->warehouse, 4);
        AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, 'min_stock' => null]);
        AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, 'min_stock' => 0]);

        $this->getJson('/api/inventory/alerts')
            ->assertOk()
            ->assertExactJson(['data' => [
                [
                    'asset_type' => ['id' => $this->gloves->id, 'name' => 'Guantes de rescate', 'category' => ['id' => $this->category->id, 'name' => 'Protección personal']],
                    'type_total' => 8,
                    'min_stock' => 10,
                ],
                [
                    'asset_type' => ['id' => $empty->id, 'name' => 'Linternas', 'category' => ['id' => $empty->asset_category_id, 'name' => $empty->category->name]],
                    'type_total' => 0,
                    'min_stock' => 2,
                ],
            ]]);
    });

    it('RN-028: un tipo inactivo no genera alerta', function () {
        actingAsRole('consulta');
        $this->gloves->forceFill(['is_active' => false])->save();

        $this->getJson('/api/inventory/alerts')->assertOk()->assertExactJson(['data' => []]);
    });
});
