<?php

use App\Actions\Entregas\CreateDelivery;
use App\Actions\Inventario\RecordStockMovement;
use App\Enums\DeliveryKind;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';
require_once __DIR__.'/../../Support/reports.php';

/**
 * 008 — T002 / US3: reporte por artículo, tipo por cantidad (D-001):
 * existencias por ubicación y movimientos en el rango (RN-033); bajo mínimo
 * sobre el total del tipo (RN-028).
 */
beforeEach(function () {
    Storage::fake('local');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Guantes (mínimo 10): entrada de 8 a Bodega el 1, traslado de 3 a Vehículo 1
 * el 5 y entrega de 2 desde Bodega el 9 (octubre de 2026, 10:00 en Bogotá).
 *
 * @return array<string, mixed>
 */
function assetTypeReportScenario(): array
{
    $s = deliveryScenario(gloves: 0);
    $s['gloves']->update(['min_stock' => 10]);
    $vehiculo = Location::factory()->create(['name' => 'Vehículo 1']);
    $movement = app(RecordStockMovement::class);
    $at = fn (int $day) => Carbon::setTestNow(Carbon::parse(sprintf('2026-10-%02d 15:00:00', $day), 'UTC'));

    $at(1);
    $entry = $movement->handle(MovementType::Entry, $s['gloves'], 8, null, $s['warehouse'], 'Compra', $s['user']);
    $at(5);
    $transfer = $movement->handle(MovementType::Transfer, $s['gloves'], 3, $s['warehouse'], $vehiculo, null, $s['user']);
    $at(9);
    $delivery = app(CreateDelivery::class)->handle($s['employee'], DeliveryKind::Dotacion, null, $s['warehouse'], null, signaturePng(), [
        ['asset_type_id' => $s['gloves']->id, 'quantity' => 2],
    ], $s['user']);

    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));

    return [...$s, 'vehiculo' => $vehiculo, 'entry' => $entry, 'transfer' => $transfer, 'delivery' => $delivery];
}

describe('GET /api/reports/asset-types/{assetType}', function () {
    it('401 sin sesión, 403 sin reportes.ver, 404 si no existe o es serializado', function () {
        $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);
        $serialized = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

        $this->getJson("/api/reports/asset-types/{$type->id}")->assertUnauthorized();

        actingWithPermissions([Permission::InventarioVer->value]);
        $this->getJson("/api/reports/asset-types/{$type->id}")->assertForbidden();

        actingWithPermissions([Permission::ReportesVer->value]);
        $this->getJson('/api/reports/asset-types/999999')->assertNotFound();
        $this->getJson("/api/reports/asset-types/{$serialized->id}")->assertNotFound();
    });

    it('US3: existencias actuales por ubicación, movimientos (más recientes primero) y totales', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = assetTypeReportScenario();
        $gloves = $s['gloves']->load('category');

        $data = $this->getJson("/api/reports/asset-types/{$gloves->id}")->assertOk()->json('data');

        expect($data['asset_type'])->toBe([
            'id' => $gloves->id, 'name' => $gloves->name,
            'category' => ['id' => $gloves->category->id, 'name' => $gloves->category->name],
            'min_stock' => 10,
        ])->and($data['stock'])->toBe([
            ['location' => ['id' => $s['warehouse']->id, 'name' => 'Bodega', 'is_active' => true], 'quantity' => 3],
            ['location' => ['id' => $s['vehiculo']->id, 'name' => 'Vehículo 1', 'is_active' => true], 'quantity' => 3],
        ]);

        expect(collect($data['movements'])->map(fn (array $m) => [$m['type'], $m['type_label'], $m['quantity'], $m['from_location']['name'] ?? null, $m['to_location']['name'] ?? null])->all())->toBe([
            ['delivery', 'Entrega', 2, 'Bodega', null],
            ['transfer', 'Traslado', 3, 'Bodega', 'Vehículo 1'],
            ['entry', 'Entrada', 8, null, 'Bodega'],
        ]);

        expect($data['movements'][0]['delivery'])->toBe(['id' => $s['delivery']->id, 'code' => $s['delivery']->code])
            ->and($data['movements'][2])->toBe([
                'id' => $s['entry']->id,
                'type' => 'entry',
                'type_label' => 'Entrada',
                'quantity' => 8,
                'from_location' => null,
                'to_location' => ['id' => $s['warehouse']->id, 'name' => 'Bodega'],
                'reason' => 'Compra',
                'delivery' => null,
                'user' => ['id' => $s['user']->id, 'name' => $s['user']->name],
                'created_at' => '2026-10-01T15:00:00.000000Z',
            ])
            ->and($data['totals'])->toBe(['stock_total' => 6, 'below_min' => true, 'movements' => 3]);
    });

    it('filtra por ubicación (origen o destino) y rango; bajo mínimo sigue sobre todas las ubicaciones', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = assetTypeReportScenario();

        $data = $this->getJson("/api/reports/asset-types/{$s['gloves']->id}?location_id={$s['vehiculo']->id}&from=2026-10-01&to=2026-10-05")
            ->assertOk()->json('data');

        expect(collect($data['stock'])->pluck('quantity')->all())->toBe([3])
            ->and(collect($data['movements'])->pluck('type')->all())->toBe(['transfer'])
            ->and($data['totals'])->toBe(['stock_total' => 3, 'below_min' => true, 'movements' => 1])
            ->and($data['filters'])->toBe([
                ['label' => 'Ubicación', 'value' => 'Vehículo 1'],
                ['label' => 'Desde', 'value' => '2026-10-01'],
                ['label' => 'Hasta', 'value' => '2026-10-05'],
            ]);
    });

    it('sin stock mínimo no está bajo mínimo; 422 con rango invertido o ubicación inexistente', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, 'min_stock' => null]);

        $this->getJson("/api/reports/asset-types/{$type->id}")
            ->assertOk()
            ->assertJsonPath('data.totals', ['stock_total' => 0, 'below_min' => false, 'movements' => 0]);

        $this->getJson("/api/reports/asset-types/{$type->id}?from=2026-10-05&to=2026-10-01&location_id=999999")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to', 'location_id']);
    });
});

describe('GET /api/reports/asset-type-options', function () {
    it('exige reportes.ver', function () {
        actingWithPermissions([Permission::InventarioVer->value]);

        $this->getJson('/api/reports/asset-type-options?search=gu')->assertForbidden();
    });

    it('solo tipos por cantidad, incluidos inactivos, búsqueda sin tildes ni mayúsculas', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $category = AssetCategory::factory()->create(['name' => 'Protección']);
        $gloves = AssetType::factory()->for($category, 'category')->create(['name' => 'Guantes', 'tracking_mode' => TrackingMode::Quantity]);
        $old = AssetType::factory()->for($category, 'category')->inactive()->create(['name' => 'Guantes viejos', 'tracking_mode' => TrackingMode::Quantity]);
        AssetType::factory()->for($category, 'category')->create(['name' => 'Guantes serializados', 'tracking_mode' => TrackingMode::Serialized]);

        $this->getJson('/api/reports/asset-type-options?search=GUÁNTES')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $gloves->id, 'name' => 'Guantes', 'is_active' => true, 'category' => ['id' => $category->id, 'name' => 'Protección']],
                ['id' => $old->id, 'name' => 'Guantes viejos', 'is_active' => false, 'category' => ['id' => $category->id, 'name' => 'Protección']],
            ]]);

        $this->getJson('/api/reports/asset-type-options')->assertOk()->assertExactJson(['data' => []]);
    });
});
