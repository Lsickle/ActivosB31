<?php

use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\AssetEvent;
use App\Models\AssetMaintenancePlan;
use App\Models\AssetMaintenanceRecord;
use Illuminate\Support\Carbon;

/**
 * 005 — Planes de mantenimiento por activo (US4, RN-029, RN-030, D-003) y
 * mantenimientos realizados (FR-005, SC-003).
 */
describe('planes (RN-029, D-003)', function () {
    it('US4-1: calcula el próximo desde el último realizado; anticipación de 30 días por defecto', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", [
            'name' => 'Prueba hidrostática', 'interval_months' => 60, 'last_done_on' => '2022-03-10',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Prueba hidrostática')
            ->assertJsonPath('data.interval_months', 60)
            ->assertJsonPath('data.last_done_on', '2022-03-10')
            ->assertJsonPath('data.next_due_on', '2027-03-10')
            ->assertJsonPath('data.alert_days', 30)
            ->assertJsonPath('data.is_active', true);

        $this->getJson("/api/assets/{$asset->id}")->assertJsonPath('data.next_maintenance_on', '2027-03-10');
    });

    it('US4-3: sin último realizado, calcula desde la fecha de creación (hoy en Bogotá)', function () {
        actingAsRole('almacenista');
        // 03:00 UTC del 1 de octubre = 22:00 del 30 de septiembre en Bogotá.
        $this->travelTo(Carbon::parse('2026-10-01 03:00:00', 'UTC'));
        $asset = Asset::factory()->create();

        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", ['name' => 'Revisión general', 'interval_months' => 12, 'alert_days' => 15])
            ->assertCreated()
            ->assertJsonPath('data.last_done_on', null)
            ->assertJsonPath('data.next_due_on', '2027-09-30')
            ->assertJsonPath('data.alert_days', 15);
    });

    it('D-003: no desborda el mes (31 de enero + 1 mes = fin de febrero)', function () {
        $plan = AssetMaintenancePlan::factory()->create(['interval_months' => 1, 'last_done_on' => '2027-01-31']);

        expect($plan->next_due_on->toDateString())->toBe('2027-02-28');
    });

    it('D-003: editar el plan recalcula el próximo; acepta todos los campos e is_active', function () {
        actingAsRole('almacenista');
        $plan = AssetMaintenancePlan::factory()->create(['interval_months' => 12, 'last_done_on' => '2026-01-10']);

        $this->patchJson("/api/assets/{$plan->asset_id}/maintenance-plans/{$plan->id}", [
            'name' => 'Revisión semestral', 'interval_months' => 6, 'last_done_on' => '2026-02-01', 'alert_days' => 10, 'is_active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Revisión semestral')
            ->assertJsonPath('data.next_due_on', '2026-08-01')
            ->assertJsonPath('data.alert_days', 10)
            ->assertJsonPath('data.is_active', false);

        $this->patchJson("/api/assets/{$plan->asset_id}/maintenance-plans/{$plan->id}", ['interval_months' => 3])
            ->assertOk()
            ->assertJsonPath('data.next_due_on', '2026-05-01');
    });

    it('lista los planes del activo sin paginar, por próximo vencimiento', function () {
        actingAsRole('consulta');
        $asset = Asset::factory()->create();
        AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'Anual', 'interval_months' => 12, 'last_done_on' => '2026-01-01']);
        AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'Mensual', 'interval_months' => 1, 'last_done_on' => '2026-01-01']);
        AssetMaintenancePlan::factory()->create();

        $this->getJson("/api/assets/{$asset->id}/maintenance-plans")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Mensual')
            ->assertJsonMissingPath('meta');
    });

    it('valida los campos y que el plan sea del activo', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();
        $tomorrow = Carbon::now('America/Bogota')->addDay()->toDateString();

        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", [])
            ->assertJsonValidationErrors(['name', 'interval_months']);
        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", [
            'name' => str_repeat('x', 121), 'interval_months' => 0, 'last_done_on' => $tomorrow, 'alert_days' => 366,
        ])->assertJsonValidationErrors(['name', 'interval_months', 'last_done_on', 'alert_days']);

        $ajeno = AssetMaintenancePlan::factory()->create();
        $this->patchJson("/api/assets/{$asset->id}/maintenance-plans/{$ajeno->id}", ['name' => 'X'])->assertNotFound();
    });
});

describe('mantenimientos realizados (FR-005)', function () {
    it('US4-2: registrar un mantenimiento actualiza el plan y queda en el historial', function () {
        $user = actingAsRole('almacenista');
        $asset = Asset::factory()->create();
        $plan = AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'Prueba hidrostática', 'interval_months' => 60, 'last_done_on' => '2022-03-10']);

        $this->postJson("/api/assets/{$asset->id}/maintenance-records", [
            'plan_id' => $plan->id, 'performed_on' => '2026-10-01', 'description' => 'Prueba hidrostática aprobada',
            'performed_by' => 'Extintores ABC', 'cost' => '150000.50',
        ])
            ->assertCreated()
            ->assertJsonPath('data.plan', ['id' => $plan->id, 'name' => 'Prueba hidrostática'])
            ->assertJsonPath('data.performed_on', '2026-10-01')
            ->assertJsonPath('data.performed_by', 'Extintores ABC')
            ->assertJsonPath('data.cost', '150000.50')
            ->assertJsonPath('data.user', ['id' => $user->id, 'name' => $user->name]);

        expect($plan->fresh()->last_done_on->toDateString())->toBe('2026-10-01')
            ->and($plan->fresh()->next_due_on->toDateString())->toBe('2031-10-01');

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::MaintenanceDone)
            ->and($event->notes)->toBe('Prueba hidrostática aprobada');
    });

    it('D-003: un mantenimiento anterior al último realizado no mueve el plan', function () {
        actingAsRole('almacenista');
        $plan = AssetMaintenancePlan::factory()->create(['interval_months' => 12, 'last_done_on' => '2026-05-01']);

        $this->postJson("/api/assets/{$plan->asset_id}/maintenance-records", [
            'plan_id' => $plan->id, 'performed_on' => '2026-01-15', 'description' => 'Registro tardío', 'cost' => 1000,
        ])->assertCreated()->assertJsonPath('data.cost', '1000.00');

        expect($plan->fresh()->last_done_on->toDateString())->toBe('2026-05-01');
    });

    it('registra mantenimientos sin plan y los lista paginados del más reciente', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->postJson("/api/assets/{$asset->id}/maintenance-records", ['performed_on' => '2026-03-01', 'description' => 'Cambio de correa'])
            ->assertCreated()
            ->assertJsonPath('data.plan', null)
            ->assertJsonPath('data.cost', null);
        $this->postJson("/api/assets/{$asset->id}/maintenance-records", ['performed_on' => '2026-06-01', 'description' => 'Limpieza'])->assertCreated();

        $this->getJson("/api/assets/{$asset->id}/maintenance-records?page=1")
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.description', 'Limpieza')
            ->assertJsonStructure(['data' => [['id', 'plan', 'performed_on', 'description', 'performed_by', 'cost', 'user', 'created_at']], 'meta']);
    });

    it('valida el registro: plan de otro activo, fecha futura, descripción y costo', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();
        $tomorrow = Carbon::now('America/Bogota')->addDay()->toDateString();

        $this->postJson("/api/assets/{$asset->id}/maintenance-records", [
            'plan_id' => AssetMaintenancePlan::factory()->create()->id, 'performed_on' => $tomorrow,
            'description' => str_repeat('x', 501), 'performed_by' => str_repeat('x', 121), 'cost' => -5,
        ])->assertJsonValidationErrors(['plan_id', 'performed_on', 'description', 'performed_by', 'cost']);

        $this->postJson("/api/assets/{$asset->id}/maintenance-records", [])->assertJsonValidationErrors(['performed_on', 'description']);
    });

    it('SC-003: los mantenimientos realizados no se editan ni se borran por la API', function () {
        actingAsRole('admin');
        $record = AssetMaintenanceRecord::query()->create([
            'asset_id' => Asset::factory()->create()->id, 'performed_on' => '2026-01-01', 'description' => 'X',
            'user_id' => auth()->id(),
        ]);

        $this->patchJson("/api/assets/{$record->asset_id}/maintenance-records/{$record->id}", [])->assertNotFound();
        $this->deleteJson("/api/assets/{$record->asset_id}/maintenance-records")->assertMethodNotAllowed();
    });

    it('un activo dado de baja admite planes y mantenimientos', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->retired()->create();

        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", ['name' => 'Revisión', 'interval_months' => 12])->assertCreated();
        $this->postJson("/api/assets/{$asset->id}/maintenance-records", ['performed_on' => '2026-01-01', 'description' => 'Revisión final'])->assertCreated();
    });
});

describe('autorización (FR-007)', function () {
    it('consultar exige activos.ver y registrar activos.gestionar', function () {
        $plan = AssetMaintenancePlan::factory()->create();
        $asset = $plan->asset;

        actingWithPermissions(['activos.ver']);
        $this->getJson("/api/assets/{$asset->id}/maintenance-plans")->assertOk();
        $this->getJson("/api/assets/{$asset->id}/maintenance-records")->assertOk();
        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", ['name' => 'X', 'interval_months' => 1])->assertForbidden();
        $this->patchJson("/api/assets/{$asset->id}/maintenance-plans/{$plan->id}", ['name' => 'X'])->assertForbidden();
        $this->postJson("/api/assets/{$asset->id}/maintenance-records", ['performed_on' => '2026-01-01', 'description' => 'X'])->assertForbidden();

        actingWithPermissions([]);
        $this->getJson("/api/assets/{$asset->id}/maintenance-plans")->assertForbidden();
        $this->getJson("/api/assets/{$asset->id}/maintenance-records")->assertForbidden();
    });
});

describe('historial de los planes (A-1, FR-004, D-002)', function () {
    it('A-1: crear un plan registra "maintenance_plan_created" con el nombre del plan', function () {
        $user = actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->postJson("/api/assets/{$asset->id}/maintenance-plans", ['name' => 'Prueba hidrostática', 'interval_months' => 60])
            ->assertCreated();

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::MaintenancePlanCreated)
            ->and($event->user_id)->toBe($user->id)
            ->and($event->notes)->toBe('Prueba hidrostática')
            ->and($event->changes)->toBeNull()
            ->and(AssetEventType::MaintenancePlanCreated->label())->toBe('Plan de mantenimiento creado');
    });

    it('A-1: editar un plan (incluido desactivarlo) registra "maintenance_plan_updated" con los cambios', function () {
        $user = actingAsRole('almacenista');
        $plan = AssetMaintenancePlan::factory()->create([
            'name' => 'Revisión anual', 'interval_months' => 12, 'last_done_on' => '2026-01-10', 'alert_days' => 30, 'is_active' => true,
        ]);

        $this->patchJson("/api/assets/{$plan->asset_id}/maintenance-plans/{$plan->id}", [
            'name' => 'Revisión semestral', 'interval_months' => 6, 'last_done_on' => '2026-02-01', 'alert_days' => 10, 'is_active' => false,
        ])->assertOk();

        $event = $plan->asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::MaintenancePlanUpdated)
            ->and($event->user_id)->toBe($user->id)
            ->and($event->notes)->toBe('Revisión semestral')
            ->and($event->changes)->toEqual([
                'name' => ['from' => 'Revisión anual', 'to' => 'Revisión semestral'],
                'interval_months' => ['from' => 12, 'to' => 6],
                'last_done_on' => ['from' => '2026-01-10', 'to' => '2026-02-01'],
                'alert_days' => ['from' => 30, 'to' => 10],
                'is_active' => ['from' => true, 'to' => false],
            ])
            ->and(AssetEventType::MaintenancePlanUpdated->label())->toBe('Plan de mantenimiento modificado');

        $this->patchJson("/api/assets/{$plan->asset_id}/maintenance-plans/{$plan->id}", ['is_active' => true])->assertOk();

        expect($plan->asset->events()->latest('id')->first()->changes)->toEqual(['is_active' => ['from' => false, 'to' => true]]);
    });

    it('A-1: una edición sin cambios efectivos no registra evento', function () {
        actingAsRole('almacenista');
        $plan = AssetMaintenancePlan::factory()->create(['name' => 'Revisión', 'interval_months' => 12, 'last_done_on' => '2026-01-10']);

        $this->patchJson("/api/assets/{$plan->asset_id}/maintenance-plans/{$plan->id}", [
            'name' => 'Revisión', 'interval_months' => 12, 'last_done_on' => '2026-01-10',
        ])->assertOk();

        expect($plan->asset->events()->count())->toBe(0);
    });

    it('A-1: el plan y su evento se guardan en la misma transacción', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        AssetEvent::creating(fn () => throw new RuntimeException('falla al registrar el evento'));

        $this->withoutExceptionHandling();
        expect(fn () => $this->postJson("/api/assets/{$asset->id}/maintenance-plans", ['name' => 'Revisión', 'interval_months' => 12]))
            ->toThrow(RuntimeException::class);

        expect($asset->maintenancePlans()->count())->toBe(0);
    });
});
