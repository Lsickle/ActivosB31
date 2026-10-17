<?php

use App\Enums\Permission;
use App\Models\Asset;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * T006 — Listado, ficha, estado derivado (D-005), alertas de préstamos
 * vencidos (FR-005, RN-026), tenencias del empleado (D-007) y permisos
 * (FR-006, D-006, RN-022).
 */
beforeEach(function () {
    Storage::fake('local');
    // 2026-10-20 03:00 UTC = 2026-10-19 22:00 en Bogotá.
    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));
});

afterEach(fn () => Carbon::setTestNow());

/**
 * @param  array<string, mixed>  $attributes
 */
function deliveryWithItem(array $attributes = [], int $quantity = 1, int $returned = 0, ?string $loanDueOn = null): Delivery
{
    $factory = Delivery::factory();
    $delivery = ($loanDueOn === null ? $factory : $factory->loan($loanDueOn))->create($attributes);
    DeliveryItem::factory()->for($delivery)->create(['quantity' => $quantity, 'returned_quantity' => $returned]);

    return $delivery;
}

describe('GET /api/deliveries', function () {
    it('401 sin sesión y 403 sin entregas.ver', function () {
        $this->getJson('/api/deliveries')->assertUnauthorized();

        actingWithPermissions([Permission::EmpleadosVer->value]);

        $this->getJson('/api/deliveries')->assertForbidden();
    });

    it('US3: paginado, más recientes primero, sin URL de firma por fila', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        $old = deliveryWithItem(['delivered_at' => now()->subDays(3)]);
        $new = deliveryWithItem(['delivered_at' => now()->subDay()]);

        $this->getJson('/api/deliveries?per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $new->id)
            ->assertJsonPath('data.0.code', $new->code)
            ->assertJsonPath('data.0.items.0.pending_quantity', 1)
            ->assertJsonMissingPath('data.0.signature_url');

        $this->getJson('/api/deliveries?per_page=1&page=2')->assertJsonPath('data.0.id', $old->id);
    });

    it('RN-022 / D-006: el empleado va solo con id, nombre completo y cargo', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        $delivery = deliveryWithItem();

        $employee = $this->getJson('/api/deliveries')->assertOk()->json('data.0.employee');

        expect($employee)->toBe([
            'id' => $delivery->employee->id,
            'full_name' => $delivery->employee->full_name,
            'position' => $delivery->employee->position->name,
        ]);
    });

    it('busca por consecutivo o por nombre del empleado sin tildes ni mayúsculas, no por documento', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        $maria = Employee::factory()->create(['first_name' => 'María José', 'last_name' => 'Gómez', 'document_number' => '80123456']);
        $byMaria = deliveryWithItem(['employee_id' => $maria->id]);
        $other = deliveryWithItem();

        $ids = fn (string $search) => collect($this->getJson('/api/deliveries?search='.urlencode($search))->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('maria jose'))->toBe([$byMaria->id])
            ->and($ids('GOMEZ MARIA'))->toBe([$byMaria->id])
            ->and($ids(strtolower($other->code)))->toBe([$other->id])
            ->and($ids('80123456'))->toBe([]);
    });

    it('filtra por empleado, tipo, estado y fechas (días en America/Bogota)', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        $employee = Employee::factory()->create();
        $closed = deliveryWithItem(['employee_id' => $employee->id, 'delivered_at' => Carbon::parse('2026-10-10 12:00', 'America/Bogota')->utc()], quantity: 2, returned: 2);
        $open = deliveryWithItem(['delivered_at' => Carbon::parse('2026-10-15 23:30', 'America/Bogota')->utc()], quantity: 2, returned: 1);
        $overdue = deliveryWithItem(['delivered_at' => Carbon::parse('2026-10-16 00:30', 'America/Bogota')->utc()], loanDueOn: '2026-10-18');
        $loanToday = deliveryWithItem(['delivered_at' => Carbon::parse('2026-10-17 08:00', 'America/Bogota')->utc()], loanDueOn: '2026-10-19');

        $ids = fn (string $query) => collect($this->getJson("/api/deliveries?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

        expect($ids("employee_id={$employee->id}"))->toBe([$closed->id])
            ->and($ids('kind=prestamo'))->toBe([$overdue->id, $loanToday->id])
            ->and($ids('status=closed'))->toBe([$closed->id])
            ->and($ids('status=open'))->toBe([$open->id, $loanToday->id])
            ->and($ids('status=overdue'))->toBe([$overdue->id])
            ->and($ids('from=2026-10-15&to=2026-10-15'))->toBe([$open->id])
            ->and($ids('from=2026-10-16'))->toBe([$overdue->id, $loanToday->id]);

        $this->getJson('/api/deliveries')->assertJsonPath('data.0.status', 'open');
        expect(collect($this->getJson('/api/deliveries')->json('data'))->firstWhere('id', $overdue->id)['status'])->toBe('overdue');
    });

    it('valida los filtros', function () {
        actingWithPermissions([Permission::EntregasVer->value]);

        $this->getJson('/api/deliveries?status=perdida&kind=regalo&from=ayer&per_page=500')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'kind', 'from', 'per_page']);
    });
});

describe('GET /api/deliveries/{id}', function () {
    it('US3: ficha con artículos, devoluciones y URL temporal de la firma', function () {
        // M-1 (provisional 33A): la firma exige entregas.gestionar o empleados.ver.
        actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);
        $asset = Asset::factory()->create(['plate' => 'CAS-777']);
        $delivery = Delivery::factory()->create();
        DeliveryItem::factory()->for($delivery)->forAsset($asset)->create();

        $this->getJson("/api/deliveries/{$delivery->id}")
            ->assertOk()
            ->assertJsonPath('data.code', $delivery->code)
            ->assertJsonPath('data.items.0.asset', ['id' => $asset->id, 'plate' => 'CAS-777'])
            ->assertJsonPath('data.items.0.asset_type.tracking_mode', 'serialized')
            ->assertJsonPath('data.signature_url', fn (string $url) => str_contains($url, $delivery->signature_path));
    });

    it('404 si no existe y 403 sin entregas.ver', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        $this->getJson('/api/deliveries/999999')->assertNotFound();

        $delivery = Delivery::factory()->create();
        actingWithPermissions([Permission::ActivosVer->value]);
        $this->getJson("/api/deliveries/{$delivery->id}")->assertForbidden();
    });
});

describe('GET /api/deliveries/alerts (FR-005, RN-026)', function () {
    it('US3-1: préstamos con pendientes y fecha esperada pasada, del más antiguo al más reciente', function () {
        actingWithPermissions([Permission::EntregasVer->value]);
        $yesterday = deliveryWithItem(quantity: 3, returned: 1, loanDueOn: '2026-10-18');
        $older = deliveryWithItem(loanDueOn: '2026-10-01');
        deliveryWithItem(loanDueOn: '2026-10-19');                       // vence hoy: aún no
        deliveryWithItem(quantity: 1, returned: 1, loanDueOn: '2026-10-01'); // devuelto: cerrado
        deliveryWithItem();                                              // dotación

        $this->getJson('/api/deliveries/alerts')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0', [
                'id' => $older->id,
                'code' => $older->code,
                'employee' => ['id' => $older->employee->id, 'full_name' => $older->employee->full_name, 'position' => $older->employee->position->name],
                'due_on' => '2026-10-01',
                'pending_quantity' => 1,
            ])
            ->assertJsonPath('data.1.id', $yesterday->id)
            ->assertJsonPath('data.1.pending_quantity', 2);
    });

    it('exige entregas.ver', function () {
        actingWithPermissions([Permission::InventarioVer->value]);

        $this->getJson('/api/deliveries/alerts')->assertForbidden();
    });
});

describe('GET /api/employees/{id}/holdings (D-007)', function () {
    it('US3-2: lista lo que el empleado tiene asignado o prestado (pendiente > 0)', function () {
        actingWithPermissions([Permission::EmpleadosVer->value]);
        $employee = Employee::factory()->create();
        $asset = Asset::factory()->create(['plate' => 'CAS-100']);

        $loan = Delivery::factory()->loan('2026-10-18')->create(['employee_id' => $employee->id, 'delivered_at' => now()->subDays(5)]);
        $helmet = DeliveryItem::factory()->for($loan)->forAsset($asset)->create();
        $gloves = DeliveryItem::factory()->for($loan)->create(['quantity' => 4, 'returned_quantity' => 1]);
        DeliveryItem::factory()->for($loan)->create(['quantity' => 2, 'returned_quantity' => 2]);

        $dotacion = Delivery::factory()->create(['employee_id' => $employee->id, 'delivered_at' => now()->subDay()]);
        $boots = DeliveryItem::factory()->for($dotacion)->create(['quantity' => 1]);

        deliveryWithItem();   // de otro empleado

        $response = $this->getJson("/api/employees/{$employee->id}/holdings")
            ->assertOk()
            ->assertJsonCount(3, 'data');

        expect(collect($response->json('data'))->pluck('delivery_item_id')->all())->toBe([$boots->id, $helmet->id, $gloves->id]);

        $response->assertJsonPath('data.1', [
            'delivery_item_id' => $helmet->id,
            'delivery' => [
                'id' => $loan->id, 'code' => $loan->code, 'kind' => 'prestamo', 'kind_label' => 'Préstamo',
                'due_on' => '2026-10-18', 'status' => 'overdue', 'delivered_at' => $loan->delivered_at->toJSON(),
            ],
            'asset' => ['id' => $asset->id, 'plate' => 'CAS-100'],
            'asset_type' => ['id' => $asset->assetType->id, 'name' => $asset->assetType->name, 'tracking_mode' => 'serialized'],
            'quantity' => 1,
            'pending_quantity' => 1,
        ])->assertJsonPath('data.2.pending_quantity', 3);
    });

    it('exige empleados.ver (no basta entregas.ver) y 404 si el empleado no existe', function () {
        $employee = Employee::factory()->create();
        actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);

        $this->getJson("/api/employees/{$employee->id}/holdings")->assertForbidden();

        actingWithPermissions([Permission::EmpleadosVer->value]);

        $this->getJson('/api/employees/999999/holdings')->assertNotFound();
    });
});

describe('permisos cruzados del asistente (contrato)', function () {
    it('GET /api/assets se autoriza con entregas.gestionar y solo muestra activos disponibles', function () {
        $available = Asset::factory()->create();
        Asset::factory()->assignedTo()->create();
        Asset::factory()->inMaintenance()->create();

        actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);

        $ids = fn (string $query) => collect($this->getJson("/api/assets?{$query}")->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('status=available'))->toBe([$available->id])
            ->and($ids(''))->toBe([$available->id])
            ->and($ids('status=assigned'))->toBe([]);
    });

    it('con activos.ver el listado de activos no cambia', function () {
        Asset::factory()->create();
        Asset::factory()->assignedTo()->create();
        actingWithPermissions([Permission::ActivosVer->value]);

        $this->getJson('/api/assets')->assertOk()->assertJsonCount(2, 'data');
    });

    it('GET /api/inventory/stock se autoriza con entregas.gestionar', function () {
        $scenario = deliveryScenario();
        actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);

        $this->getJson("/api/inventory/stock?asset_type_id={$scenario['gloves']->id}&location_id={$scenario['warehouse']->id}")
            ->assertOk()
            ->assertJsonPath('data.0.quantity', 20);
    });

    it('entregas.ver sin gestionar no abre activos ni existencias', function () {
        actingWithPermissions([Permission::EntregasVer->value]);

        $this->getJson('/api/assets?status=available')->assertForbidden();
        $this->getJson('/api/inventory/stock')->assertForbidden();
        $this->getJson('/api/assets/alerts')->assertForbidden();
        $this->getJson('/api/inventory/alerts')->assertForbidden();
    });
});
