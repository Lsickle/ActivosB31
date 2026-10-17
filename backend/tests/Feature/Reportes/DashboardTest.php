<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use App\Models\AssetType;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * 008 — T004 / US4: tablero de Inicio (D-004). Solo exige sesión; cada
 * sección aparece solo si el usuario puede ver su módulo. Las alertas tienen
 * la misma forma (y salen de la misma consulta) que las de 005, 006 y 007.
 */
beforeEach(function () {
    // 2026-10-20 03:00 UTC = 2026-10-19 en Bogotá.
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

function dashboardScenario(): void
{
    $location = Location::factory()->create();
    Asset::factory()->count(2)->at($location)->create();
    Asset::factory()->at($location)->inMaintenance()->create(['expires_on' => '2026-10-25']);
    Asset::factory()->retired()->create(['expires_on' => '2026-10-01']);
    Asset::factory()->assignedTo()->create();
    AssetMaintenancePlan::factory()->for(Asset::query()->where('status', 'available')->first())->create(['interval_months' => 12, 'last_done_on' => '2025-10-01']);

    $gloves = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, 'min_stock' => 10]);
    app(RecordStockMovement::class)->handle(MovementType::Entry, $gloves, 3, null, $location, null, User::factory()->create());

    // Abierta, vencida y cerrada.
    DeliveryItem::factory()->for(Delivery::factory()->create())->create(['quantity' => 2]);
    DeliveryItem::factory()->for(Delivery::factory()->loan('2026-10-10')->create())->create(['quantity' => 3, 'returned_quantity' => 1]);
    DeliveryItem::factory()->for(Delivery::factory()->create())->create(['quantity' => 1, 'returned_quantity' => 1]);
}

describe('GET /api/dashboard', function () {
    it('401 sin sesión', function () {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    });

    it('sin permisos de módulo responde 200 sin secciones', function () {
        actingWithPermissions([Permission::ReportesVer->value]);

        $this->getJson('/api/dashboard')->assertOk()->assertExactJson(['data' => []]);
    });

    it('activos.ver: conteo por estado, total y las mismas alertas que GET /api/assets/alerts', function () {
        actingWithPermissions([Permission::ActivosVer->value]);
        dashboardScenario();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        expect(array_keys($data))->toBe(['assets'])
            ->and($data['assets']['by_status'])->toBe(['available' => 2, 'assigned' => 1, 'maintenance' => 1, 'retired' => 1])
            ->and($data['assets']['total'])->toBe(5)
            ->and($data['assets']['alerts'])->toBe($this->getJson('/api/assets/alerts')->json('data'))
            ->and($data['assets']['alerts']['maintenance'])->toHaveCount(1)
            ->and($data['assets']['alerts']['expirations'])->toHaveCount(1);
    });

    it('inventario.ver: tipos bajo mínimo, igual que GET /api/inventory/alerts', function () {
        actingWithPermissions([Permission::InventarioVer->value]);
        dashboardScenario();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        expect(array_keys($data))->toBe(['inventory'])
            ->and($data['inventory']['below_min'])->toBe($this->getJson('/api/inventory/alerts')->json('data'))
            ->and($data['inventory']['below_min'])->toHaveCount(1)
            ->and($data['inventory']['below_min'][0])->toHaveKeys(['asset_type', 'type_total', 'min_stock']);
    });

    it('entregas.ver: abiertas y vencidas (sin solaparse) y la lista de GET /api/deliveries/alerts', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        dashboardScenario();

        $data = $this->getJson('/api/dashboard')->assertOk()->json('data');

        expect(array_keys($data))->toBe(['deliveries'])
            ->and($data['deliveries']['open_count'])->toBe(1)
            ->and($data['deliveries']['overdue_count'])->toBe(1)
            ->and($data['deliveries']['overdue'])->toBe($this->getJson('/api/deliveries/alerts')->json('data'))
            ->and($data['deliveries']['overdue'][0]['pending_quantity'])->toBe(2);
    });

    it('el Administrador ve las tres secciones', function () {
        actingAsRole('admin');
        dashboardScenario();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'assets' => ['by_status' => ['available', 'assigned', 'maintenance', 'retired'], 'total', 'alerts' => ['maintenance', 'expirations']],
                'inventory' => ['below_min'],
                'deliveries' => ['open_count', 'overdue_count', 'overdue'],
            ]]);
    });

    it('exige la contraseña cambiada (password.changed)', function () {
        actingWithPermissions([Permission::ActivosVer->value], ['must_change_password' => true]);

        $this->getJson('/api/dashboard')->assertForbidden();
    });
});
