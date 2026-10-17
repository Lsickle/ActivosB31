<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

require_once __DIR__.'/../../Support/reports.php';

/**
 * 008 — T002 / US1: inventario general (D-001), con reportes.ver (FR-003).
 * SC-002: los totales coinciden con las existencias de Inventario (006).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Bodega y Vehículo; categoría Protección con cascos (serializados) y
 * guantes (por cantidad, mínimo 10); categoría Herramientas con palas.
 *
 * @return array<string, mixed>
 */
function inventoryScenario(): array
{
    $bodega = Location::factory()->create(['name' => 'Bodega']);
    $vehiculo = Location::factory()->create(['name' => 'Vehículo 1']);
    $proteccion = AssetCategory::factory()->create(['name' => 'Protección']);
    $herramientas = AssetCategory::factory()->create(['name' => 'Herramientas']);
    $cascos = AssetType::factory()->for($proteccion, 'category')->create(['name' => 'Casco', 'tracking_mode' => TrackingMode::Serialized]);
    $guantes = AssetType::factory()->for($proteccion, 'category')->create(['name' => 'Guantes', 'tracking_mode' => TrackingMode::Quantity, 'min_stock' => 10]);
    $palas = AssetType::factory()->for($herramientas, 'category')->create(['name' => 'Pala', 'tracking_mode' => TrackingMode::Quantity]);
    $user = User::factory()->create();

    Asset::factory()->count(2)->for($cascos, 'assetType')->at($bodega)->create();
    Asset::factory()->for($cascos, 'assetType')->at($bodega)->inMaintenance()->create();
    Asset::factory()->for($cascos, 'assetType')->at($vehiculo)->create();
    Asset::factory()->for($cascos, 'assetType')->assignedTo(Employee::factory()->create())->create();

    $movement = app(RecordStockMovement::class);
    $movement->handle(MovementType::Entry, $guantes, 6, null, $bodega, null, $user);
    $movement->handle(MovementType::Entry, $guantes, 2, null, $vehiculo, null, $user);
    $movement->handle(MovementType::Entry, $palas, 3, null, $bodega, null, $user);

    return compact('bodega', 'vehiculo', 'proteccion', 'herramientas', 'cascos', 'guantes', 'palas');
}

describe('GET /api/reports/inventory', function () {
    it('401 sin sesión y 403 sin reportes.ver (FR-003)', function () {
        $this->getJson('/api/reports/inventory')->assertUnauthorized();

        actingWithPermissions([Permission::InventarioVer->value, Permission::ActivosVer->value]);

        $this->getJson('/api/reports/inventory')->assertForbidden();
        getReport('/api/reports/inventory?format=xlsx')->assertForbidden();
    });

    it('US1: serializados por tipo, ubicación y estado; por cantidad con total y mínimo (RN-028)', function () {
        $user = actingWithPermissions([Permission::ReportesVer->value]);
        $s = inventoryScenario();

        $data = $this->getJson('/api/reports/inventory')->assertOk()->json('data');

        expect($data['generated_by'])->toBe(['id' => $user->id, 'name' => $user->name])
            ->and($data['generated_at'])->toBe('2026-10-20T03:00:00.000000Z')
            ->and($data['filters'])->toBe([]);

        $category = ['id' => $s['proteccion']->id, 'name' => 'Protección'];
        $casco = ['id' => $s['cascos']->id, 'name' => 'Casco', 'category' => $category];

        expect($data['serialized'])->toBe([
            ['asset_type' => $casco, 'location' => ['id' => $s['bodega']->id, 'name' => 'Bodega'], 'status' => 'available', 'status_label' => 'Disponible', 'count' => 2],
            ['asset_type' => $casco, 'location' => ['id' => $s['bodega']->id, 'name' => 'Bodega'], 'status' => 'maintenance', 'status_label' => 'En mantenimiento', 'count' => 1],
            ['asset_type' => $casco, 'location' => ['id' => $s['vehiculo']->id, 'name' => 'Vehículo 1'], 'status' => 'available', 'status_label' => 'Disponible', 'count' => 1],
            ['asset_type' => $casco, 'location' => null, 'status' => 'assigned', 'status_label' => 'Asignado', 'count' => 1],
        ]);

        // Orden: categoría (Herramientas antes que Protección), tipo, ubicación.
        expect($data['quantity'])->toBe([
            [
                'asset_type' => ['id' => $s['palas']->id, 'name' => 'Pala', 'category' => ['id' => $s['herramientas']->id, 'name' => 'Herramientas']],
                'location' => ['id' => $s['bodega']->id, 'name' => 'Bodega', 'is_active' => true],
                'quantity' => 3, 'type_total' => 3, 'min_stock' => null, 'below_min' => false,
            ],
            [
                'asset_type' => ['id' => $s['guantes']->id, 'name' => 'Guantes', 'category' => $category],
                'location' => ['id' => $s['bodega']->id, 'name' => 'Bodega', 'is_active' => true],
                'quantity' => 6, 'type_total' => 8, 'min_stock' => 10, 'below_min' => true,
            ],
            [
                'asset_type' => ['id' => $s['guantes']->id, 'name' => 'Guantes', 'category' => $category],
                'location' => ['id' => $s['vehiculo']->id, 'name' => 'Vehículo 1', 'is_active' => true],
                'quantity' => 2, 'type_total' => 8, 'min_stock' => 10, 'below_min' => true,
            ],
        ]);

        expect($data['totals'])->toBe([
            'serialized' => 5,
            'by_status' => ['available' => 3, 'assigned' => 1, 'maintenance' => 1, 'retired' => 0],
            'quantity_units' => 11,
            'below_min_types' => 1,
        ]);
    });

    it('filtra por categoría, tipo y ubicación; el estado solo filtra los serializados', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = inventoryScenario();

        $byLocation = $this->getJson("/api/reports/inventory?location_id={$s['vehiculo']->id}")->assertOk()->json('data');
        expect(collect($byLocation['serialized'])->pluck('count')->all())->toBe([1])
            ->and(collect($byLocation['quantity'])->pluck('quantity')->all())->toBe([2])
            // type_total y below_min sobre todas las ubicaciones (como 006).
            ->and($byLocation['quantity'][0]['type_total'])->toBe(8)
            ->and($byLocation['filters'])->toBe([['label' => 'Ubicación', 'value' => 'Vehículo 1']]);

        $byCategory = $this->getJson("/api/reports/inventory?asset_category_id={$s['herramientas']->id}")->json('data');
        expect($byCategory['serialized'])->toBe([])
            ->and(collect($byCategory['quantity'])->pluck('asset_type.name')->all())->toBe(['Pala']);

        $byType = $this->getJson("/api/reports/inventory?asset_type_id={$s['guantes']->id}")->json('data');
        expect(collect($byType['quantity'])->pluck('quantity')->all())->toBe([6, 2])
            ->and($byType['serialized'])->toBe([]);

        $byStatus = $this->getJson('/api/reports/inventory?status=maintenance')->json('data');
        expect(collect($byStatus['serialized'])->pluck('status')->all())->toBe(['maintenance'])
            ->and($byStatus['totals']['by_status'])->toBe(['available' => 0, 'assigned' => 0, 'maintenance' => 1, 'retired' => 0])
            ->and($byStatus['quantity'])->toHaveCount(3)
            ->and($byStatus['filters'])->toBe([['label' => 'Estado', 'value' => 'En mantenimiento']]);

        $all = $this->getJson("/api/reports/inventory?asset_category_id={$s['proteccion']->id}&asset_type_id={$s['cascos']->id}&location_id={$s['bodega']->id}&status=available")->json('data');
        expect($all['filters'])->toBe([
            ['label' => 'Categoría', 'value' => 'Protección'],
            ['label' => 'Tipo', 'value' => 'Casco'],
            ['label' => 'Ubicación', 'value' => 'Bodega'],
            ['label' => 'Estado', 'value' => 'Disponible'],
        ])->and(collect($all['serialized'])->pluck('count')->all())->toBe([2]);
    });

    it('incluye los dados de baja y omite existencias en cero', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = inventoryScenario();
        Asset::factory()->for($s['cascos'], 'assetType')->at($s['bodega'])->retired()->create();
        app(RecordStockMovement::class)->handle(MovementType::Exit, $s['palas'], 3, $s['bodega'], null, 'Consumo', User::factory()->create());

        $data = $this->getJson('/api/reports/inventory')->json('data');

        expect($data['totals']['by_status']['retired'])->toBe(1)
            ->and(collect($data['quantity'])->pluck('asset_type.name')->all())->toBe(['Guantes', 'Guantes']);
    });

    it('SC-002: los totales por cantidad coinciden con GET /api/inventory/stock', function () {
        actingWithPermissions([Permission::ReportesVer->value, Permission::InventarioVer->value]);
        inventoryScenario();

        $report = $this->getJson('/api/reports/inventory')->json('data');
        $stock = collect($this->getJson('/api/inventory/stock?per_page=100')->json('data'));

        expect($report['totals']['quantity_units'])->toBe($stock->sum('quantity'))
            ->and(collect($report['quantity'])->count())->toBe($stock->count());
    });

    it('valida filtros y formato (422)', function () {
        actingWithPermissions([Permission::ReportesVer->value]);

        $this->getJson('/api/reports/inventory?status=perdido&location_id=999999&asset_category_id=abc&format=csv')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'location_id', 'asset_category_id', 'format']);
    });
});
