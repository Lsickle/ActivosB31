<?php

use App\Enums\SecurityEventType;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use App\Models\AssetType;
use App\Models\SecurityEvent;
use Illuminate\Support\Carbon;

/**
 * 005 — Alertas de mantenimientos y vencimientos (US4-4, US5, RN-030,
 * RN-031, D-004) y ajustes generales (D-005).
 */
beforeEach(function () {
    // 03:00 UTC del 1 de octubre = 22:00 del 30 de septiembre en Bogotá (D-004).
    $this->travelTo(Carbon::parse('2026-10-01 03:00:00', 'UTC'));
});

describe('alertas (D-004)', function () {
    it('US4-4: lista planes activos dentro de su anticipación y vencidos, sin activos dados de baja', function () {
        actingAsRole('consulta');
        $type = AssetType::factory()->create(['name' => 'Cilindro SCBA', 'tracking_mode' => 'serialized']);
        $asset = Asset::factory()->for($type, 'assetType')->create(['plate' => 'B31-0001']);

        // last_done_on + 12 meses = next_due_on.
        $vencido = AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'Vencido', 'last_done_on' => '2025-09-29']);
        $hoy = AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'Hoy', 'last_done_on' => '2025-09-30']);
        $enAnticipacion = AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'En 30 días', 'last_done_on' => '2025-10-30']);
        AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'En 31 días', 'last_done_on' => '2025-10-31']);
        AssetMaintenancePlan::factory()->for($asset)->create(['name' => 'En 31 días con 60', 'last_done_on' => '2025-10-31', 'alert_days' => 60]);
        AssetMaintenancePlan::factory()->for($asset)->inactive()->create(['last_done_on' => '2025-01-01']);
        AssetMaintenancePlan::factory()->for(Asset::factory()->retired())->create(['last_done_on' => '2025-01-01']);

        $response = $this->getJson('/api/assets/alerts')->assertOk();

        expect(collect($response->json('data.maintenance'))->pluck('plan_name')->all())
            ->toBe(['Vencido', 'Hoy', 'En 30 días', 'En 31 días con 60']);

        $response
            ->assertJsonPath('data.maintenance.0', [
                'plan_id' => $vencido->id,
                'plan_name' => 'Vencido',
                'next_due_on' => '2026-09-29',
                'overdue' => true,
                'asset' => ['id' => $asset->id, 'plate' => 'B31-0001', 'asset_type' => ['id' => $type->id, 'name' => 'Cilindro SCBA']],
            ])
            ->assertJsonPath('data.maintenance.1.plan_id', $hoy->id)
            ->assertJsonPath('data.maintenance.1.overdue', false)
            ->assertJsonPath('data.maintenance.2.plan_id', $enAnticipacion->id);
    });

    it('US5-1 / US5-2 / RN-031: vencimientos dentro de la anticipación global y vencidos, nunca dados de baja', function () {
        actingAsRole('consulta');
        $type = AssetType::factory()->create(['name' => 'Casco', 'tracking_mode' => 'serialized']);
        $proximo = Asset::factory()->for($type, 'assetType')->create(['plate' => 'B31-0002', 'expires_on' => '2026-10-20']);
        $vencido = Asset::factory()->for($type, 'assetType')->inMaintenance()->create(['expires_on' => '2026-09-29']);
        $hoy = Asset::factory()->for($type, 'assetType')->assignedTo()->create(['expires_on' => '2026-09-30']);
        Asset::factory()->create(['expires_on' => '2026-10-31']);
        Asset::factory()->create(['expires_on' => null]);
        Asset::factory()->retired()->create(['expires_on' => '2026-01-01']);

        $response = $this->getJson('/api/assets/alerts')->assertOk();

        expect(collect($response->json('data.expirations'))->pluck('asset.id')->all())->toBe([$vencido->id, $hoy->id, $proximo->id]);
        $response
            ->assertJsonPath('data.expirations.0.overdue', true)
            ->assertJsonPath('data.expirations.1.overdue', false)
            ->assertJsonPath('data.expirations.2', [
                'expires_on' => '2026-10-20',
                'overdue' => false,
                'asset' => ['id' => $proximo->id, 'plate' => 'B31-0002', 'asset_type' => ['id' => $type->id, 'name' => 'Casco']],
            ]);
    });

    it('US5-3: cambiar la anticipación global recalcula las alertas', function () {
        actingAsRole('consulta');
        Asset::factory()->create(['expires_on' => '2026-11-15']);

        $this->getJson('/api/assets/alerts')->assertJsonCount(0, 'data.expirations');

        AppSetting::put(AppSetting::EXPIRY_ALERT_DAYS, 60);

        $this->getJson('/api/assets/alerts')->assertJsonCount(1, 'data.expirations');
    });

    it('FR-007: las alertas exigen activos.ver', function () {
        $this->getJson('/api/assets/alerts')->assertUnauthorized();

        actingWithPermissions(['configuracion.ver']);
        $this->getJson('/api/assets/alerts')->assertForbidden();
    });
});

describe('ajustes (D-005)', function () {
    it('cualquier usuario con algún permiso de ver consulta los ajustes', function () {
        actingWithPermissions(['activos.ver']);

        $this->getJson('/api/settings')
            ->assertOk()
            ->assertExactJson(['data' => ['expiry_alert_days' => 30]]);

        actingWithPermissions([]);
        $this->getJson('/api/settings')->assertForbidden();
    });

    it('RN-030: el Administrador cambia la anticipación de vencimientos (0 a 365 días)', function () {
        actingAsRole('admin');

        $this->putJson('/api/settings', ['expiry_alert_days' => 45])
            ->assertOk()
            ->assertJsonPath('data.expiry_alert_days', 45);
        expect(AppSetting::expiryAlertDays())->toBe(45);

        $this->putJson('/api/settings', ['expiry_alert_days' => 0])->assertOk();
        $this->putJson('/api/settings', ['expiry_alert_days' => 366])->assertJsonValidationErrors('expiry_alert_days');
        $this->putJson('/api/settings', ['expiry_alert_days' => -1])->assertJsonValidationErrors('expiry_alert_days');
        $this->putJson('/api/settings', [])->assertJsonValidationErrors('expiry_alert_days');
        expect(AppSetting::expiryAlertDays())->toBe(0);
    });

    it('B-5: cambiar los ajustes queda en la bitácora de seguridad con el valor anterior y el nuevo', function () {
        $admin = actingAsRole('admin');

        $this->putJson('/api/settings', ['expiry_alert_days' => 45], ['User-Agent' => 'Pruebas/1.0'])->assertOk();

        $event = SecurityEvent::query()->sole();
        expect($event->event)->toBe(SecurityEventType::SettingsUpdated)
            ->and($event->actor_id)->toBe($admin->id)
            ->and($event->user_agent)->toBe('Pruebas/1.0')
            ->and($event->metadata)->toBe(['changes' => ['expiry_alert_days' => [30, 45]]])
            ->and(SecurityEventType::SettingsUpdated->label())->toBe('Ajustes modificados');

        // Sin cambio efectivo no hay evento nuevo.
        $this->putJson('/api/settings', ['expiry_alert_days' => 45])->assertOk();
        expect(SecurityEvent::query()->count())->toBe(1);
    });

    it('FR-006: cambiar los ajustes exige configuracion.gestionar', function () {
        actingAsRole('almacenista');

        $this->putJson('/api/settings', ['expiry_alert_days' => 10])->assertForbidden();
        expect(AppSetting::expiryAlertDays())->toBe(30);
    });
});
