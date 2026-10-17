<?php

use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Location;
use App\Models\LocationType;

/**
 * 005 — Estados, ubicación y baja (US2, RN-005, D-001) con historial
 * (US2-4, FR-004).
 */
describe('cambio de estado (D-001)', function () {
    it('US2-1: envía a mantenimiento y devuelve a disponible, con historial', function () {
        $user = actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'maintenance', 'reason' => 'Fuga en la válvula'])
            ->assertOk()
            ->assertJsonPath('data.status', 'maintenance')
            ->assertJsonPath('data.status_label', 'En mantenimiento');

        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'available'])
            ->assertOk()
            ->assertJsonPath('data.status', 'available');

        $events = $asset->events()->orderBy('id')->get();
        expect($events)->toHaveCount(2)
            ->and($events[0]->event)->toBe(AssetEventType::StatusChanged)
            ->and($events[0]->from_status)->toBe(AssetStatus::Available)
            ->and($events[0]->to_status)->toBe(AssetStatus::Maintenance)
            ->and($events[0]->notes)->toBe('Fuga en la válvula')
            ->and($events[0]->user_id)->toBe($user->id)
            ->and($events[1]->to_status)->toBe(AssetStatus::Available)
            ->and($events[1]->notes)->toBeNull();
    });

    it('US2-3: da de baja con motivo obligatorio y es definitiva', function (string $from) {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create(['status' => $from]);

        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'retired'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');
        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'retired', 'reason' => str_repeat('x', 501)])
            ->assertJsonValidationErrors('reason');

        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'retired', 'reason' => 'Casco con fisura'])
            ->assertOk()
            ->assertJsonPath('data.status', 'retired')
            ->assertJsonPath('data.status_label', 'Dado de baja');

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::Retired)
            ->and($event->to_status)->toBe(AssetStatus::Retired)
            ->and($event->notes)->toBe('Casco con fisura');

        foreach (['available', 'maintenance'] as $target) {
            $this->patchJson("/api/assets/{$asset->id}/status", ['status' => $target])
                ->assertConflict()
                ->assertJsonPath('message', 'El activo está dado de baja: la baja es definitiva.');
        }
    })->with(['available', 'maintenance']);

    it('US2-2: un activo asignado no cambia de estado manualmente (409)', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->assignedTo()->create();

        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'maintenance'])
            ->assertConflict()
            ->assertJsonPath('message', 'El activo está asignado: primero debe devolverse en Entregas.');

        expect($asset->fresh()->status)->toBe(AssetStatus::Assigned)
            ->and($asset->events()->count())->toBe(0);
    });

    it('D-001: "Asignado" no se elige manualmente y repetir el estado actual es un conflicto', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'assigned'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.status.0', 'El estado Asignado solo lo ponen y quitan las entregas.');
        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'perdido'])->assertJsonValidationErrors('status');
        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'available'])
            ->assertConflict()
            ->assertJsonPath('message', 'El activo ya está en ese estado.');
    });
});

describe('cambio de ubicación', function () {
    it('traslada el activo y lo registra en el historial', function () {
        actingAsRole('almacenista');
        $bodega = Location::factory()->create();
        $maquina = Location::factory()->create(['name' => 'Máquina 1']);
        $asset = Asset::factory()->at($bodega)->inMaintenance()->create();

        $this->patchJson("/api/assets/{$asset->id}/location", ['location_id' => $maquina->id])
            ->assertOk()
            ->assertJsonPath('data.location.name', 'Máquina 1')
            ->assertJsonPath('data.status', 'maintenance');

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::LocationChanged)
            ->and($event->from_location_id)->toBe($bodega->id)
            ->and($event->to_location_id)->toBe($maquina->id);

        // Misma ubicación: sin cambios ni evento.
        $this->patchJson("/api/assets/{$asset->id}/location", ['location_id' => $maquina->id])->assertOk();
        expect($asset->events()->count())->toBe(1);
    });

    it('edge case: no traslada a ubicaciones no disponibles', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->patchJson("/api/assets/{$asset->id}/location", ['location_id' => Location::factory()->inactive()->create()->id])
            ->assertJsonPath('errors.location_id.0', 'La ubicación seleccionada está inactiva.');
        $this->patchJson("/api/assets/{$asset->id}/location", ['location_id' => Location::factory()->for(LocationType::factory()->inactive())->create()->id])
            ->assertJsonValidationErrors('location_id');
        $this->patchJson("/api/assets/{$asset->id}/location", [])->assertJsonValidationErrors('location_id');
    });

    it('US2-2 / D-001: un activo asignado o dado de baja no cambia de ubicación (409)', function () {
        actingAsRole('almacenista');
        $destino = Location::factory()->create();

        $this->patchJson('/api/assets/'.Asset::factory()->assignedTo()->create()->id.'/location', ['location_id' => $destino->id])
            ->assertConflict()
            ->assertJsonPath('message', 'El activo está asignado: primero debe devolverse en Entregas.');
        $this->patchJson('/api/assets/'.Asset::factory()->retired()->create()->id.'/location', ['location_id' => $destino->id])
            ->assertConflict()
            ->assertJsonPath('message', 'El activo está dado de baja: la baja es definitiva.');
    });
});

describe('dado de baja', function () {
    it('un activo dado de baja todavía admite editar sus datos', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->retired()->create();

        $this->patchJson("/api/assets/{$asset->id}", ['notes' => 'En bodega de bajas'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'En bodega de bajas')
            ->assertJsonPath('data.status', 'retired');
    });
});

describe('historial (US3)', function () {
    it('lista el historial paginado del más reciente al más antiguo', function () {
        actingAsRole('almacenista');
        $bodega = Location::factory()->create(['name' => 'Bodega']);
        $maquina = Location::factory()->create(['name' => 'Máquina 1']);
        $asset = Asset::factory()->at($bodega)->create(['brand' => 'MSA']);

        $this->patchJson("/api/assets/{$asset->id}", ['brand' => 'Dräger'])->assertOk();
        $this->patchJson("/api/assets/{$asset->id}/location", ['location_id' => $maquina->id])->assertOk();
        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'retired', 'reason' => 'Vencido'])->assertOk();

        actingAsRole('consulta');
        $this->getJson("/api/assets/{$asset->id}/events")
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.event', 'retired')
            ->assertJsonPath('data.0.event_label', 'Baja')
            ->assertJsonPath('data.0.from_status', 'available')
            ->assertJsonPath('data.0.to_status', 'retired')
            ->assertJsonPath('data.0.notes', 'Vencido')
            ->assertJsonPath('data.0.changes', null)
            ->assertJsonPath('data.1.event', 'location_changed')
            ->assertJsonPath('data.1.from_location', ['id' => $bodega->id, 'name' => 'Bodega'])
            ->assertJsonPath('data.1.to_location', ['id' => $maquina->id, 'name' => 'Máquina 1'])
            ->assertJsonPath('data.2.event', 'updated')
            ->assertJsonPath('data.2.changes.brand.from', 'MSA')
            ->assertJsonPath('data.2.changes.brand.to', 'Dräger')
            ->assertJsonStructure(['data' => [[
                'id', 'event', 'event_label', 'from_status', 'to_status', 'from_location', 'to_location',
                'employee', 'user' => ['id', 'name'], 'notes', 'changes', 'created_at',
            ]], 'meta', 'links']);
    });
});

describe('autorización (FR-007)', function () {
    it('cambiar estado o ubicación exige activos.gestionar; el historial, activos.ver', function () {
        $asset = Asset::factory()->create();

        actingWithPermissions(['activos.ver']);
        $this->patchJson("/api/assets/{$asset->id}/status", ['status' => 'maintenance'])->assertForbidden();
        $this->patchJson("/api/assets/{$asset->id}/location", ['location_id' => Location::factory()->create()->id])->assertForbidden();
        $this->getJson("/api/assets/{$asset->id}/events")->assertOk();

        actingWithPermissions([]);
        $this->getJson("/api/assets/{$asset->id}/events")->assertForbidden();
    });
});
