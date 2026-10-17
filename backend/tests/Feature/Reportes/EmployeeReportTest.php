<?php

use App\Actions\Entregas\CreateDelivery;
use App\Actions\Entregas\RegisterDeliveryReturn;
use App\Enums\DeliveryKind;
use App\Enums\Permission;
use App\Models\Company;
use App\Models\Delivery;
use App\Models\Employee;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';
require_once __DIR__.'/../../Support/reports.php';

/**
 * 008 — T002 / US2: reporte por empleado (D-001, D-003). Datos personales
 * solo con empleados.ver (RN-022); rango inclusivo en America/Bogota (RN-033).
 */
beforeEach(function () {
    Storage::fake('local');
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Dotación (casco + 2 guantes) el 2026-10-05; préstamo de 3 guantes el
 * 2026-10-09 a las 23:00 en Bogotá (2026-10-10 04:00 UTC) con vencimiento el
 * 15; el 12 devuelve 1 guante de la dotación y 2 del préstamo. "Hoy" es el
 * 2026-10-19 en Bogotá.
 *
 * @return array<string, mixed>
 */
function employeeReportScenario(): array
{
    $s = deliveryScenario(gloves: 20);
    $create = app(CreateDelivery::class);

    Carbon::setTestNow(Carbon::parse('2026-10-05 15:00:00', 'UTC'));
    $dotacion = $create->handle($s['employee'], DeliveryKind::Dotacion, null, $s['warehouse'], null, signaturePng(), [
        ['asset_id' => $s['helmet']->id],
        ['asset_type_id' => $s['gloves']->id, 'quantity' => 2],
    ], $s['user'])->load('items');

    Carbon::setTestNow(Carbon::parse('2026-10-10 04:00:00', 'UTC'));
    $prestamo = $create->handle($s['employee'], DeliveryKind::Prestamo, '2026-10-15', $s['warehouse'], null, signaturePng(), [
        ['asset_type_id' => $s['gloves']->id, 'quantity' => 3],
    ], $s['user'])->load('items');

    Carbon::setTestNow(Carbon::parse('2026-10-12 15:00:00', 'UTC'));
    $return = app(RegisterDeliveryReturn::class);
    $return->handle($dotacion, [
        ['delivery_item_id' => $dotacion->items->firstWhere('asset_id', null)->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $s['warehouse']->id],
    ], $s['user']);
    $return->handle($prestamo, [
        ['delivery_item_id' => $prestamo->items->first()->id, 'quantity' => 2, 'condition' => 'good', 'to_location_id' => $s['warehouse']->id],
    ], $s['user']);

    Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));

    return [...$s, 'dotacion' => $dotacion, 'prestamo' => $prestamo];
}

describe('GET /api/reports/employees/{employee}', function () {
    it('401 sin sesión, 403 sin reportes.ver y 404 si no existe', function () {
        $employee = Employee::factory()->create();

        $this->getJson("/api/reports/employees/{$employee->id}")->assertUnauthorized();

        actingWithPermissions([Permission::EmpleadosVer->value, Permission::EntregasVer->value]);
        $this->getJson("/api/reports/employees/{$employee->id}")->assertForbidden();

        actingWithPermissions([Permission::ReportesVer->value]);
        $this->getJson('/api/reports/employees/999999')->assertNotFound();
    });

    it('RN-022 / D-003: sin empleados.ver solo nombre y cargo (las claves personales no vienen)', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $employee = Employee::factory()->create(['first_name' => 'Ana', 'last_name' => 'Rojas', 'phone' => '3001234567', 'email' => 'ana@example.com']);

        $data = $this->getJson("/api/reports/employees/{$employee->id}")->assertOk()->json('data');

        expect($data['employee'])->toBe([
            'id' => $employee->id,
            'full_name' => 'Ana Rojas',
            'position' => $employee->position->name,
            'is_active' => true,
        ]);
    });

    it('RN-022: con empleados.ver incluye documento, contacto y compañía', function () {
        actingWithPermissions([Permission::ReportesVer->value, Permission::EmpleadosVer->value]);
        $company = Company::factory()->create(['name' => 'Compañía A']);
        $employee = Employee::factory()->for($company)->create([
            'first_name' => 'Ana', 'last_name' => 'Rojas', 'document_number' => '80123456', 'phone' => '3001234567', 'email' => 'ana@example.com',
        ]);

        $data = $this->getJson("/api/reports/employees/{$employee->id}")->assertOk()->json('data');

        expect($data['employee'])->toBe([
            'id' => $employee->id,
            'full_name' => 'Ana Rojas',
            'position' => $employee->position->name,
            'is_active' => true,
            'document_type' => 'CC',
            'document_number' => '80123456',
            'phone' => '3001234567',
            'email' => 'ana@example.com',
            'company' => 'Compañía A',
        ]);
    });

    it('incluye empleados inactivos', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $employee = Employee::factory()->inactive()->create();

        $this->getJson("/api/reports/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.employee.is_active', false);
    });

    it('US2: lo que tiene hoy, el historial completo (más reciente primero) y los totales', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = employeeReportScenario();

        $data = $this->getJson("/api/reports/employees/{$s['employee']->id}")->assertOk()->json('data');

        expect($data['holdings'])->toHaveCount(3)
            ->and($data['holdings'][0])->toBe([
                'delivery' => [
                    'id' => $s['prestamo']->id, 'code' => $s['prestamo']->code, 'kind' => 'prestamo', 'kind_label' => 'Préstamo',
                    'due_on' => '2026-10-15', 'status' => 'overdue',
                ],
                'asset' => null,
                'asset_type' => ['id' => $s['gloves']->id, 'name' => $s['gloves']->name, 'tracking_mode' => 'quantity'],
                'pending_quantity' => 1,
                'delivered_at' => '2026-10-10T04:00:00.000000Z',
            ])
            ->and($data['holdings'][1]['asset'])->toBe(['id' => $s['helmet']->id, 'plate' => $s['helmet']->plate])
            ->and($data['holdings'][1]['delivery']['status'])->toBe('open');

        expect(collect($data['history'])->map(fn (array $row) => [$row['event'], $row['delivery']['code'], $row['quantity'], $row['condition']])->all())->toBe([
            ['return', $s['prestamo']->code, 2, 'good'],
            ['return', $s['dotacion']->code, 1, 'good'],
            ['delivery', $s['prestamo']->code, 3, null],
            ['delivery', $s['dotacion']->code, 2, null],
            ['delivery', $s['dotacion']->code, 1, null],
        ]);

        expect($data['history'][0])->toBe([
            'date' => '2026-10-12T15:00:00.000000Z',
            'event' => 'return',
            'event_label' => 'Devolución',
            'delivery' => ['id' => $s['prestamo']->id, 'code' => $s['prestamo']->code, 'kind' => 'prestamo', 'kind_label' => 'Préstamo'],
            'asset' => null,
            'asset_type' => ['id' => $s['gloves']->id, 'name' => $s['gloves']->name],
            'quantity' => 2,
            'condition' => 'good',
            'condition_label' => 'Bueno',
        ])->and($data['history'][4]['asset'])->toBe(['id' => $s['helmet']->id, 'plate' => $s['helmet']->plate])
            ->and($data['history'][4]['event_label'])->toBe('Entrega')
            ->and($data['history'][4]['condition_label'])->toBeNull();

        expect($data['totals'])->toBe(['pending_units' => 3, 'delivered_units' => 6, 'returned_units' => 3]);
    });

    it('RN-033: el rango es inclusivo por día en America/Bogota y no afecta lo que tiene hoy', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = employeeReportScenario();

        // El préstamo se registró el 2026-10-10 04:00 UTC = 2026-10-09 23:00 en Bogotá.
        $data = $this->getJson("/api/reports/employees/{$s['employee']->id}?from=2026-10-09&to=2026-10-09")->assertOk()->json('data');

        expect(collect($data['history'])->pluck('delivery.code')->all())->toBe([$s['prestamo']->code])
            ->and($data['holdings'])->toHaveCount(3)
            ->and($data['totals'])->toBe(['pending_units' => 3, 'delivered_units' => 3, 'returned_units' => 0])
            ->and($data['filters'])->toBe([['label' => 'Desde', 'value' => '2026-10-09'], ['label' => 'Hasta', 'value' => '2026-10-09']]);

        $fromOnly = $this->getJson("/api/reports/employees/{$s['employee']->id}?from=2026-10-12")->json('data');
        expect(collect($fromOnly['history'])->pluck('event')->all())->toBe(['return', 'return']);
    });

    it('RN-033: hasta anterior a desde → 422 en `to`; fechas mal formadas → 422', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $employee = Employee::factory()->create();

        $this->getJson("/api/reports/employees/{$employee->id}?from=2026-10-10&to=2026-10-09")
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors(['to']);

        $this->getJson("/api/reports/employees/{$employee->id}?from=10/09/2026")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['from']);
    });

    it('no mezcla entregas de otros empleados', function () {
        actingWithPermissions([Permission::ReportesVer->value]);
        $s = employeeReportScenario();
        $other = Employee::factory()->create();

        $data = $this->getJson("/api/reports/employees/{$other->id}")->json('data');

        expect($data['holdings'])->toBe([])
            ->and($data['history'])->toBe([])
            ->and($data['totals'])->toBe(['pending_units' => 0, 'delivered_units' => 0, 'returned_units' => 0])
            ->and(Delivery::query()->where('employee_id', $s['employee']->id)->count())->toBe(2);
    });
});
