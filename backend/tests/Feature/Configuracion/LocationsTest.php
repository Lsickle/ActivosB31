<?php

use App\Models\Location;
use App\Models\LocationType;
use Illuminate\Support\Facades\DB;

it('FR-004 / FR-013: lista paginada por nombre con su tipo de ubicación', function () {
    actingAsRole('consulta');
    $vehiculo = LocationType::factory()->create(['name' => 'Vehículo']);
    Location::factory()->for($vehiculo, 'locationType')->create(['name' => 'Máquina 1']);
    Location::factory()->for($vehiculo, 'locationType')->create(['name' => 'Ambulancia 2']);

    $this->getJson('/api/locations')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.name', 'Ambulancia 2')
        ->assertJsonPath('data.0.location_type', ['id' => $vehiculo->id, 'name' => 'Vehículo', 'is_active' => true])
        ->assertJsonStructure(['data' => [['id', 'name', 'description', 'is_active', 'location_type' => ['id', 'name', 'is_active'], 'created_at', 'updated_at']], 'meta', 'links']);
});

it('FR-004: filtra por tipo de ubicación, estado y búsqueda sin tildes', function () {
    actingAsRole('consulta');
    $bodega = LocationType::factory()->create();
    $vehiculo = LocationType::factory()->create();
    Location::factory()->for($bodega, 'locationType')->create(['name' => 'Bodega principal']);
    Location::factory()->for($vehiculo, 'locationType')->create(['name' => 'Máquina 1']);
    Location::factory()->for($vehiculo, 'locationType')->inactive()->create(['name' => 'Máquina vieja']);

    $this->getJson("/api/locations?location_type_id={$vehiculo->id}")->assertJsonCount(2, 'data');
    $this->getJson('/api/locations?search=MAQUINA&is_active=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Máquina 1');
    $this->getJson('/api/locations?location_type_id=abc')->assertJsonValidationErrors('location_type_id');
});

it('US3-2: crea una ubicación activa asociada a un tipo activo', function () {
    actingAsRole('admin');
    $vehiculo = LocationType::factory()->create(['name' => 'Vehículo']);

    $this->postJson('/api/locations', ['location_type_id' => $vehiculo->id, 'name' => 'Máquina 1'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Máquina 1')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.location_type.id', $vehiculo->id);
});

it('US3-1 / FR-007: exige un tipo de ubicación existente y activo', function () {
    actingAsRole('admin');
    $oficina = LocationType::factory()->inactive()->create();

    $this->postJson('/api/locations', ['name' => 'Recepción'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.location_type_id.0', 'El campo tipo de ubicación es obligatorio.');
    $this->postJson('/api/locations', ['location_type_id' => $oficina->id, 'name' => 'Recepción'])
        ->assertJsonPath('errors.location_type_id.0', 'El tipo de ubicación seleccionado está inactivo.');
    $this->postJson('/api/locations', ['location_type_id' => 999999, 'name' => 'Recepción'])
        ->assertJsonPath('errors.location_type_id.0', 'El valor seleccionado en tipo de ubicación no es válido.');
});

it('US3-3 / FR-006: el nombre es único globalmente sin distinguir mayúsculas', function () {
    actingAsRole('admin');
    $bodega = LocationType::factory()->create();
    $vehiculo = LocationType::factory()->create();
    Location::factory()->for($vehiculo, 'locationType')->create(['name' => 'Máquina 1']);

    $this->postJson('/api/locations', ['location_type_id' => $bodega->id, 'name' => 'máquina 1'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
});

it('US3-4 / FR-007: al editar conserva su tipo inactivo, pero no se cambia a otro inactivo', function () {
    actingAsRole('admin');
    $oficina = LocationType::factory()->inactive()->create();
    $otroInactivo = LocationType::factory()->inactive()->create();
    $bodega = LocationType::factory()->create();
    $location = Location::factory()->for($oficina, 'locationType')->create(['name' => 'Recepción']);

    $this->patchJson("/api/locations/{$location->id}", ['location_type_id' => $oficina->id, 'name' => 'Recepción principal'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Recepción principal')
        ->assertJsonPath('data.location_type.id', $oficina->id)
        ->assertJsonPath('data.location_type.is_active', false);

    $this->patchJson("/api/locations/{$location->id}", ['location_type_id' => $otroInactivo->id])
        ->assertJsonPath('errors.location_type_id.0', 'El tipo de ubicación seleccionado está inactivo.');

    $this->patchJson("/api/locations/{$location->id}", ['location_type_id' => $bodega->id])
        ->assertOk()
        ->assertJsonPath('data.location_type.id', $bodega->id);
});

it('B-4: un nombre duplicado detectado por la base de datos responde 422', function () {
    actingAsRole('admin');
    $bodega = LocationType::factory()->create();
    Location::creating(function (Location $location) {
        DB::table('locations')->insert([
            'location_type_id' => $location->location_type_id,
            'name' => $location->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    });

    $this->postJson('/api/locations', ['location_type_id' => $bodega->id, 'name' => 'Bodega principal'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
});

it('RN-003: desactiva y reactiva; no existe DELETE (405)', function () {
    actingAsRole('admin');
    $location = Location::factory()->create();

    $this->patchJson("/api/locations/{$location->id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
    $this->patchJson("/api/locations/{$location->id}/status", ['is_active' => true])
        ->assertJsonPath('data.is_active', true);

    $this->deleteJson("/api/locations/{$location->id}")->assertMethodNotAllowed();
});

it('RN-011: 401 sin sesión, 403 sin ver y 403 al modificar sin gestionar', function () {
    $location = Location::factory()->create();

    $this->getJson('/api/locations')->assertUnauthorized();

    actingWithPermissions([]);
    $this->getJson('/api/locations')->assertForbidden();
    $this->getJson("/api/locations/{$location->id}")->assertForbidden();

    actingAsRole('almacenista');
    $this->getJson("/api/locations/{$location->id}")->assertOk();
    $this->postJson('/api/locations', [])->assertForbidden();
    $this->patchJson("/api/locations/{$location->id}", ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/locations/{$location->id}/status", ['is_active' => false])->assertForbidden();
});
