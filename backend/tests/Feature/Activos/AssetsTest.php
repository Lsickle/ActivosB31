<?php

use App\Actions\Activos\UpdateAsset;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetMaintenancePlan;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use App\Models\LocationType;
use App\Models\Position;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 005 — CRUD de activos serializados (US1, FR-001, FR-002, FR-007, D-006,
 * D-007) e historial de registro y edición (FR-004, D-002).
 */
function serializedType(array $attributes = []): AssetType
{
    return AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized, ...$attributes]);
}

/**
 * @return array<string, mixed>
 */
function assetPayload(array $overrides = []): array
{
    return [
        'asset_type_id' => serializedType(['name' => 'Cilindro SCBA'])->id,
        'plate' => 'B31-0001',
        'serial' => 'SN-123',
        'brand' => 'MSA',
        'model' => 'G1',
        'acquisition_date' => '2024-05-10',
        'acquisition_value' => '1250000.50',
        'useful_life_months' => 180,
        'expires_on' => '2039-05-10',
        'notes' => 'Cilindro de 45 minutos',
        ...$overrides,
    ];
}

describe('registro (US1)', function () {
    it('US1-1: registra un activo serializado disponible con su historial de registro', function () {
        $user = actingAsRole('almacenista');
        $bodega = Location::factory()->create(['name' => 'Bodega principal']);

        $response = $this->postJson('/api/assets', assetPayload(['plate' => '  b31-0001 ', 'location_id' => $bodega->id]))
            ->assertCreated()
            ->assertJsonPath('data.plate', 'B31-0001')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.status_label', 'Disponible')
            ->assertJsonPath('data.serial', 'SN-123')
            ->assertJsonPath('data.acquisition_date', '2024-05-10')
            ->assertJsonPath('data.acquisition_value', '1250000.50')
            ->assertJsonPath('data.useful_life_months', 180)
            ->assertJsonPath('data.expires_on', '2039-05-10')
            ->assertJsonPath('data.asset_type.name', 'Cilindro SCBA')
            ->assertJsonPath('data.asset_type.tracking_mode', 'serialized')
            ->assertJsonPath('data.location.id', $bodega->id)
            ->assertJsonPath('data.location.name', 'Bodega principal')
            ->assertJsonPath('data.holder', null)
            ->assertJsonPath('data.photo_url', null)
            ->assertJsonPath('data.next_maintenance_on', null)
            ->assertJsonPath('data.notes', 'Cilindro de 45 minutos')
            ->assertJsonStructure(['data' => [
                'id', 'plate', 'serial', 'brand', 'model', 'acquisition_date', 'acquisition_value', 'useful_life_months',
                'expires_on', 'status', 'status_label', 'notes', 'photo_url',
                'asset_type' => ['id', 'name', 'tracking_mode', 'category' => ['id', 'name']],
                'location' => ['id', 'name', 'is_active'], 'holder', 'next_maintenance_on', 'created_at', 'updated_at',
            ]]);

        $asset = Asset::query()->findOrFail($response->json('data.id'));
        $event = $asset->events()->sole();

        expect($event->event)->toBe(AssetEventType::Created)
            ->and($event->to_status)->toBe(AssetStatus::Available)
            ->and($event->to_location_id)->toBe($bodega->id)
            ->and($event->user_id)->toBe($user->id);
    });

    it('registra con solo tipo y placa; los opcionales vacíos quedan en null', function () {
        actingAsRole('almacenista');

        $this->postJson('/api/assets', ['asset_type_id' => serializedType()->id, 'plate' => 'B31-0002', 'serial' => '', 'brand' => ' '])
            ->assertCreated()
            ->assertJsonPath('data.serial', null)
            ->assertJsonPath('data.brand', null)
            ->assertJsonPath('data.location', null);
    });

    it('US1-2 / RN-008 / D-006: rechaza una placa repetida sin distinguir mayúsculas', function () {
        actingAsRole('almacenista');
        Asset::factory()->create(['plate' => 'B31-0001']);

        $this->postJson('/api/assets', assetPayload(['plate' => 'b31-0001']))
            ->assertUnprocessable()
            ->assertJsonPath('errors.plate.0', 'El valor del campo placa ya está en uso.');
    });

    it('B-4: una placa duplicada detectada por la base de datos responde 422', function () {
        actingAsRole('almacenista');
        Asset::creating(function (Asset $asset) {
            DB::table('assets')->insert([
                'asset_type_id' => $asset->asset_type_id, 'plate' => $asset->plate,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/assets', assetPayload())
            ->assertUnprocessable()
            ->assertJsonPath('errors.plate.0', 'El valor del campo placa ya está en uso.');
    });

    it('US1-3 / RN-007: rechaza tipos por cantidad', function () {
        actingAsRole('almacenista');
        $guantes = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);

        $this->postJson('/api/assets', assetPayload(['asset_type_id' => $guantes->id]))
            ->assertUnprocessable()
            ->assertJsonPath('errors.asset_type_id.0', 'Los artículos de un tipo por cantidad se manejan en Inventario, no como activos.');
    });

    it('US1-4 / RN-003 / D-007: rechaza un tipo inactivo o de categoría inactiva', function () {
        actingAsRole('almacenista');
        $inactivo = serializedType(['is_active' => false]);
        $deCategoriaInactiva = serializedType(['asset_category_id' => AssetCategory::factory()->inactive()->create()->id]);

        foreach ([$inactivo, $deCategoriaInactiva] as $type) {
            $this->postJson('/api/assets', assetPayload(['asset_type_id' => $type->id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.asset_type_id.0', 'El tipo de activo seleccionado está inactivo o su categoría está inactiva.');
        }

        $this->postJson('/api/assets', assetPayload(['asset_type_id' => 999999]))->assertJsonValidationErrors('asset_type_id');
    });

    it('edge case: no ofrece ubicaciones inactivas ni de un tipo de ubicación inactivo', function () {
        actingAsRole('almacenista');
        $inactiva = Location::factory()->inactive()->create();
        $deTipoInactivo = Location::factory()->for(LocationType::factory()->inactive())->create();

        foreach ([$inactiva, $deTipoInactivo] as $location) {
            $this->postJson('/api/assets', assetPayload(['location_id' => $location->id]))
                ->assertUnprocessable()
                ->assertJsonPath('errors.location_id.0', 'La ubicación seleccionada está inactiva.');
        }
    });

    it('edge case: rechaza valor negativo, adquisición futura y vida útil ≤ 0', function () {
        actingAsRole('almacenista');
        $tomorrow = Carbon::now(Asset::LOCAL_TIMEZONE)->addDay()->toDateString();

        $this->postJson('/api/assets', assetPayload(['acquisition_value' => -1, 'acquisition_date' => $tomorrow, 'useful_life_months' => 0]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['acquisition_value', 'acquisition_date', 'useful_life_months'])
            ->assertJsonPath('errors.acquisition_date.0', 'La fecha de adquisición no puede ser futura.');

        $this->postJson('/api/assets', [])->assertJsonValidationErrors(['asset_type_id', 'plate']);
        $this->postJson('/api/assets', assetPayload(['status' => 'assigned']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'available');
    });
});

describe('consulta (US1-5)', function () {
    it('lista paginado por placa con búsqueda y filtros', function () {
        actingAsRole('consulta');
        $epp = AssetCategory::factory()->create();
        $casco = serializedType(['asset_category_id' => $epp->id]);
        $radio = serializedType();
        $bodega = Location::factory()->create();
        $empleado = Employee::factory()->create();

        $a = Asset::factory()->for($casco, 'assetType')->at($bodega)->create(['plate' => 'B31-0002', 'brand' => 'MSA', 'model' => 'Cairns 1044']);
        $b = Asset::factory()->for($radio, 'assetType')->inMaintenance()->create(['plate' => 'B31-0001', 'serial' => 'XK-9', 'brand' => 'Motorola', 'model' => 'DEP 450']);
        $c = Asset::factory()->for($casco, 'assetType')->assignedTo($empleado)->create(['plate' => 'B31-0003', 'brand' => 'Bullard']);

        $this->getJson('/api/assets')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.plate', 'B31-0001')
            ->assertJsonPath('data.1.plate', 'B31-0002');

        $ids = fn (string $query) => collect($this->getJson("/api/assets?{$query}")->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('search=xk-9'))->toBe([$b->id])
            ->and($ids('search=motor'))->toBe([$b->id])
            ->and($ids('search=CAIRNS'))->toBe([$a->id])
            ->and($ids('search=b31-000'))->toBe([$b->id, $a->id, $c->id])
            ->and($ids("asset_category_id={$epp->id}"))->toBe([$a->id, $c->id])
            ->and($ids("asset_type_id={$radio->id}"))->toBe([$b->id])
            ->and($ids('status=maintenance'))->toBe([$b->id])
            ->and($ids("location_id={$bodega->id}"))->toBe([$a->id])
            ->and($ids("holder_employee_id={$empleado->id}"))->toBe([$c->id])
            ->and($ids('per_page=1&page=2'))->toBe([$a->id]);

        $this->getJson('/api/assets?status=perdido')->assertJsonValidationErrors('status');
    });

    it('muestra la ficha con poseedor mínimo, próximo mantenimiento y planes', function () {
        actingAsRole('consulta');
        $empleado = Employee::factory()->for(Position::factory()->state(['name' => 'Bombero']))->create(['first_name' => 'Ana', 'last_name' => 'Ruiz']);
        $asset = Asset::factory()->assignedTo($empleado)->create();
        AssetMaintenancePlan::factory()->for($asset)->create(['interval_months' => 12, 'last_done_on' => '2026-01-15']);
        AssetMaintenancePlan::factory()->for($asset)->create(['interval_months' => 6, 'last_done_on' => '2026-01-15']);
        AssetMaintenancePlan::factory()->for($asset)->inactive()->create(['interval_months' => 1, 'last_done_on' => '2026-01-15']);

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.status_label', 'Asignado')
            ->assertJsonPath('data.holder', ['id' => $empleado->id, 'full_name' => 'Ana Ruiz', 'position' => 'Bombero'])
            ->assertJsonPath('data.next_maintenance_on', '2026-07-15')
            ->assertJsonCount(3, 'data.maintenance_plans');
    });

    it('edge case: un activo de un tipo luego desactivado conserva el tipo con la indicación', function () {
        actingAsRole('consulta');
        $type = serializedType();
        $asset = Asset::factory()->for($type, 'assetType')->create();
        $type->forceFill(['is_active' => false])->save();

        $this->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.asset_type.id', $type->id)
            ->assertJsonPath('data.asset_type.is_active', false);
    });
});

describe('edición (FR-001, D-002)', function () {
    it('edita los datos y registra el evento "updated" con los cambios', function () {
        $user = actingAsRole('almacenista');
        $asset = Asset::factory()->create(['plate' => 'B31-0001', 'brand' => 'MSA', 'model' => 'G1']);

        $this->patchJson("/api/assets/{$asset->id}", ['plate' => 'b31-0100', 'brand' => 'Dräger', 'model' => 'G1'])
            ->assertOk()
            ->assertJsonPath('data.plate', 'B31-0100')
            ->assertJsonPath('data.brand', 'Dräger');

        $event = $asset->events()->sole();
        expect($event->event)->toBe(AssetEventType::Updated)
            ->and($event->user_id)->toBe($user->id)
            ->and($event->changes)->toEqual([
                'plate' => ['from' => 'B31-0001', 'to' => 'B31-0100'],
                'brand' => ['from' => 'MSA', 'to' => 'Dräger'],
            ]);
    });

    it('acepta POST como edición (multipart) y no registra evento si nada cambió', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create(['plate' => 'B31-0001']);

        $this->post("/api/assets/{$asset->id}", ['plate' => 'B31-0001'], ['Accept' => 'application/json'])->assertOk();

        expect($asset->events()->count())->toBe(0);
    });

    it('B-2: calcula los cambios contra la fila bloqueada, no contra una copia desactualizada', function () {
        $user = actingAsRole('almacenista');
        $asset = Asset::factory()->create(['brand' => 'MSA']);
        $stale = Asset::query()->findOrFail($asset->id);
        Asset::query()->whereKey($asset->id)->update(['brand' => 'Dräger']);

        app(UpdateAsset::class)->handle($stale, ['brand' => 'MSA'], $user);

        expect($asset->fresh()->brand)->toBe('MSA')
            ->and($stale->brand)->toBe('MSA')
            ->and($asset->events()->sole()->changes)->toEqual(['brand' => ['from' => 'Dräger', 'to' => 'MSA']]);
    });

    it('D-006: valida la placa única ignorando el propio activo', function () {
        actingAsRole('almacenista');
        Asset::factory()->create(['plate' => 'B31-0002']);
        $asset = Asset::factory()->create(['plate' => 'B31-0001']);

        $this->patchJson("/api/assets/{$asset->id}", ['plate' => 'b31-0001'])->assertOk();
        $this->patchJson("/api/assets/{$asset->id}", ['plate' => 'B31-0002'])->assertJsonValidationErrors('plate');
        $this->patchJson("/api/assets/{$asset->id}", ['plate' => ''])->assertJsonValidationErrors('plate');
    });

    it('D-007: conserva su tipo inactivo, pero no cambia a otro inactivo ni a uno por cantidad', function () {
        actingAsRole('almacenista');
        $type = serializedType(['is_active' => false]);
        $asset = Asset::factory()->for($type, 'assetType')->create();

        $this->patchJson("/api/assets/{$asset->id}", ['asset_type_id' => $type->id, 'brand' => 'Otra'])->assertOk();
        $this->patchJson("/api/assets/{$asset->id}", ['asset_type_id' => serializedType(['is_active' => false])->id])
            ->assertJsonValidationErrors('asset_type_id');
        $this->patchJson("/api/assets/{$asset->id}", ['asset_type_id' => AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity])->id])
            ->assertJsonValidationErrors('asset_type_id');

        $nuevo = serializedType();
        $this->patchJson("/api/assets/{$asset->id}", ['asset_type_id' => $nuevo->id])
            ->assertOk()
            ->assertJsonPath('data.asset_type.id', $nuevo->id);
    });

    it('la edición no cambia el estado ni la ubicación (tienen sus propias rutas)', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->patchJson("/api/assets/{$asset->id}", ['status' => 'retired', 'location_id' => Location::factory()->create()->id, 'brand' => 'X'])
            ->assertOk()
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.location', null);
    });
});

describe('autorización (FR-007)', function () {
    it('401 sin sesión, 403 sin activos.ver, 403 al modificar sin activos.gestionar y sin DELETE', function () {
        $asset = Asset::factory()->create();

        $this->getJson('/api/assets')->assertUnauthorized();

        actingWithPermissions([]);
        $this->getJson('/api/assets')->assertForbidden();
        $this->getJson("/api/assets/{$asset->id}")->assertForbidden();

        actingWithPermissions(['activos.ver']);
        $this->getJson('/api/assets')->assertOk();
        $this->getJson("/api/assets/{$asset->id}")->assertOk();
        $this->postJson('/api/assets', assetPayload())->assertForbidden();
        $this->patchJson("/api/assets/{$asset->id}", ['brand' => 'X'])->assertForbidden();

        actingAsRole('admin');
        $this->deleteJson("/api/assets/{$asset->id}")->assertMethodNotAllowed();
        $this->getJson('/api/assets/999999')->assertNotFound();
    });
});

describe('identificadores en la ruta (B-4)', function () {
    it('B-4: un id de activo o de plan no numérico da 404 en todas las rutas', function (string $method, string $uri) {
        actingAsRole('admin');
        $plan = AssetMaintenancePlan::factory()->create();

        $this->json($method, str_replace(['{asset}', '{plan}'], [(string) $plan->asset_id, (string) $plan->id], $uri))
            ->assertNotFound();
    })->with([
        'ficha' => ['GET', '/api/assets/abc'],
        'foto' => ['POST', '/api/assets/abc/photo'],
        'quitar foto' => ['DELETE', '/api/assets/1e3/photo'],
        'estado' => ['PATCH', '/api/assets/abc/status'],
        'ubicación' => ['PATCH', '/api/assets/abc/location'],
        'historial' => ['GET', '/api/assets/abc/events'],
        'planes' => ['GET', '/api/assets/abc/maintenance-plans'],
        'realizados' => ['GET', '/api/assets/abc/maintenance-records'],
        'plan no numérico' => ['PATCH', '/api/assets/{asset}/maintenance-plans/abc'],
        'plan con signo' => ['PATCH', '/api/assets/{asset}/maintenance-plans/-1'],
    ]);
});
