<?php

use App\Actions\Activos\ChangeAssetLocation;
use App\Actions\Activos\ChangeAssetStatus;
use App\Actions\Entregas\CreateDelivery;
use App\Actions\Entregas\RegisterDeliveryReturn;
use App\Enums\AssetStatus;
use App\Enums\DeliveryKind;
use App\Enums\Permission;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetMaintenancePlan;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';
require_once __DIR__.'/../../Support/reports.php';

/**
 * 008 — T002 / US3: reporte por artículo, activo serializado (D-001):
 * ficha, estado actual e historial (asset_events) en el rango (RN-033).
 */
beforeEach(function () {
    Storage::fake('local');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * El casco: a Vehículo 1 el 3, En mantenimiento el 4 (motivo "Revisión"),
 * Disponible el 6, entregado a Ana Rojas el 8 y devuelto en buen estado a
 * Vehículo 1 el 12 (octubre de 2026, 10:00 en Bogotá).
 *
 * @return array<string, mixed>
 */
function assetReportScenario(): array
{
    $s = deliveryScenario(gloves: 0);
    $vehiculo = Location::factory()->create(['name' => 'Vehículo 1']);
    $at = fn (int $day) => Carbon::setTestNow(Carbon::parse(sprintf('2026-10-%02d 15:00:00', $day), 'UTC'));

    $at(3);
    app(ChangeAssetLocation::class)->handle($s['helmet'], $vehiculo, $s['user']);
    $at(4);
    app(ChangeAssetStatus::class)->handle($s['helmet']->refresh(), AssetStatus::Maintenance, $s['user'], 'Revisión');
    $at(6);
    app(ChangeAssetStatus::class)->handle($s['helmet']->refresh(), AssetStatus::Available, $s['user']);
    $at(8);
    $delivery = app(CreateDelivery::class)->handle($s['employee'], DeliveryKind::Dotacion, null, $vehiculo, null, signaturePng(), [
        ['asset_id' => $s['helmet']->id],
    ], $s['user'])->load('items');
    $at(12);
    app(RegisterDeliveryReturn::class)->handle($delivery, [
        ['delivery_item_id' => $delivery->items->first()->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $vehiculo->id],
    ], $s['user']);

    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));

    return [...$s, 'vehiculo' => $vehiculo, 'delivery' => $delivery];
}

describe('GET /api/reports/assets/{asset}', function () {
    it('401 sin sesión, 403 sin reportes.ver (aunque tenga activos.ver) y 404 si no existe', function () {
        $asset = Asset::factory()->create();

        $this->getJson("/api/reports/assets/{$asset->id}")->assertUnauthorized();

        actingWithPermissions([Permission::ActivosVer->value]);
        $this->getJson("/api/reports/assets/{$asset->id}")->assertForbidden();

        actingWithPermissions([Permission::ReportesVer->value]);
        $this->getJson('/api/reports/assets/999999')->assertNotFound();
    });

    it('US3: ficha del activo con estado, ubicación, poseedor (RN-039) y próximo mantenimiento', function () {
        $user = actingWithPermissions([Permission::ReportesVer->value]);
        $category = AssetCategory::factory()->create(['name' => 'Protección']);
        $type = AssetType::factory()->for($category, 'category')->create(['name' => 'Casco', 'tracking_mode' => TrackingMode::Serialized]);
        $location = Location::factory()->create(['name' => 'Bodega']);
        $holder = Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '80123456']);
        $asset = Asset::factory()->for($type, 'assetType')->at($location)->assignedTo($holder)->create([
            'plate' => 'CAS-001', 'serial' => 'SN-1', 'brand' => 'MSA', 'model' => 'F1',
            'acquisition_date' => '2025-01-10', 'expires_on' => '2030-01-10',
        ]);
        AssetMaintenancePlan::factory()->for($asset)->create(['interval_months' => 12, 'last_done_on' => '2026-01-15']);
        AssetMaintenancePlan::factory()->for($asset)->inactive()->create(['interval_months' => 1, 'last_done_on' => '2026-01-15']);

        $data = $this->getJson("/api/reports/assets/{$asset->id}")->assertOk()->json('data');

        expect($data['generated_by'])->toBe(['id' => $user->id, 'name' => $user->name])
            ->and($data['asset'])->toBe([
                'id' => $asset->id,
                'plate' => 'CAS-001',
                'serial' => 'SN-1',
                'brand' => 'MSA',
                'model' => 'F1',
                'status' => 'assigned',
                'status_label' => 'Asignado',
                'asset_type' => ['id' => $type->id, 'name' => 'Casco', 'category' => ['id' => $category->id, 'name' => 'Protección']],
                'location' => ['id' => $location->id, 'name' => 'Bodega'],
                // RN-022: nombre y cargo, sin documento.
                'holder' => ['id' => $holder->id, 'full_name' => 'Ana Rojas', 'position' => $holder->position->name],
                'acquisition_date' => '2025-01-10',
                'expires_on' => '2030-01-10',
                'next_maintenance_on' => '2027-01-15',
            ])
            ->and($data['events'])->toBe([])
            ->and($data['totals'])->toBe(['events' => 0]);
    });

    it('incluye activos dados de baja', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $asset = Asset::factory()->retired()->create();

        $this->getJson("/api/reports/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.asset.status', 'retired')
            ->assertJsonPath('data.asset.status_label', 'Dado de baja')
            ->assertJsonPath('data.asset.holder', null);
    });

    it('US3: historial más reciente primero con el detalle armado por el servidor', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = assetReportScenario();

        $data = $this->getJson("/api/reports/assets/{$s['helmet']->id}")->assertOk()->json('data');
        $code = $s['delivery']->code;

        expect(collect($data['events'])->map(fn (array $event) => [$event['event'], $event['event_label'], $event['detail']])->all())->toBe([
            ['returned', 'Devolución', "Devuelto por Ana Rojas. Entrega {$code}. Asignado → Disponible. Ubicación: Vehículo 1"],
            ['delivered', 'Entrega', "Entregado a Ana Rojas. Entrega {$code}"],
            ['status_changed', 'Cambio de estado', 'En mantenimiento → Disponible'],
            ['status_changed', 'Cambio de estado', 'Disponible → En mantenimiento. Motivo: Revisión'],
            ['location_changed', 'Cambio de ubicación', 'Bodega → Vehículo 1'],
        ]);

        expect($data['events'][0])->toHaveKeys(['id', 'event', 'event_label', 'detail', 'user', 'created_at'])
            ->and($data['events'][0]['user'])->toBe(['id' => $s['user']->id, 'name' => $s['user']->name])
            ->and($data['events'][0]['created_at'])->toBe('2026-10-12T15:00:00.000000Z')
            ->and($data['totals'])->toBe(['events' => 5]);
    });

    it('RN-033: limita el historial al rango (inclusivo) y lo informa en los filtros', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = assetReportScenario();

        $data = $this->getJson("/api/reports/assets/{$s['helmet']->id}?from=2026-10-04&to=2026-10-06")->assertOk()->json('data');

        expect(collect($data['events'])->pluck('event')->all())->toBe(['status_changed', 'status_changed'])
            ->and($data['totals'])->toBe(['events' => 2])
            ->and($data['filters'])->toBe([['label' => 'Desde', 'value' => '2026-10-04'], ['label' => 'Hasta', 'value' => '2026-10-06']]);

        $this->getJson("/api/reports/assets/{$s['helmet']->id}?from=2026-10-06&to=2026-10-04")
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['to']);
    });
});

describe('GET /api/reports/asset-options', function () {
    it('exige reportes.ver', function () {
        actingWithPermissions([Permission::ActivosVer->value]);

        $this->getJson('/api/reports/asset-options?search=B31')->assertForbidden();
    });

    it('busca por placa o serial sin distinguir mayúsculas, todos los estados, ordenado por placa', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $type = AssetType::factory()->create(['name' => 'Casco', 'tracking_mode' => TrackingMode::Serialized]);
        $retired = Asset::factory()->for($type, 'assetType')->retired()->create(['plate' => 'CAS-002', 'serial' => 'X']);
        $available = Asset::factory()->for($type, 'assetType')->create(['plate' => 'CAS-001', 'serial' => 'Y']);
        $bySerial = Asset::factory()->for($type, 'assetType')->create(['plate' => 'ZZZ-9', 'serial' => 'sn-cas-77']);
        Asset::factory()->create(['plate' => 'OTRO-1', 'serial' => 'Z']);

        $this->getJson('/api/reports/asset-options?search=cas')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['id' => $available->id, 'plate' => 'CAS-001', 'status' => 'available', 'status_label' => 'Disponible', 'asset_type' => ['id' => $type->id, 'name' => 'Casco']],
                ['id' => $retired->id, 'plate' => 'CAS-002', 'status' => 'retired', 'status_label' => 'Dado de baja', 'asset_type' => ['id' => $type->id, 'name' => 'Casco']],
                ['id' => $bySerial->id, 'plate' => 'ZZZ-9', 'status' => 'available', 'status_label' => 'Disponible', 'asset_type' => ['id' => $type->id, 'name' => 'Casco']],
            ]]);
    });

    it('sin búsqueda devuelve una lista vacía y como máximo 20 resultados', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        Asset::factory()->count(22)->create();

        $this->getJson('/api/reports/asset-options')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/reports/asset-options?search=B31')->assertOk()->assertJsonCount(20, 'data');
    });
});
