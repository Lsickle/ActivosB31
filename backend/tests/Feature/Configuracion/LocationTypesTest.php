<?php

use App\Models\Location;
use App\Models\LocationType;
use Illuminate\Support\Facades\DB;

it('FR-003 / FR-013: lista paginada por nombre con el conteo de ubicaciones', function () {
    actingAsRole('consulta');
    LocationType::factory()->has(Location::factory()->count(2))->create(['name' => 'Vehículo']);
    LocationType::factory()->create(['name' => 'Bodega']);

    $this->getJson('/api/location-types')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('data.0.name', 'Bodega')
        ->assertJsonPath('data.0.locations_count', 0)
        ->assertJsonPath('data.1.locations_count', 2)
        ->assertJsonStructure(['data' => [['id', 'name', 'description', 'is_active', 'locations_count', 'created_at', 'updated_at']], 'meta', 'links']);
});

it('D-003: busca sin tildes y filtra por estado', function () {
    actingAsRole('consulta');
    LocationType::factory()->create(['name' => 'Vehículo']);
    LocationType::factory()->inactive()->create(['name' => 'Oficina']);

    $this->getJson('/api/location-types?search=vehiculo')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Vehículo');
    $this->getJson('/api/location-types?is_active=false')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Oficina');
});

it('RN-004: crea, edita y muestra un tipo de ubicación', function () {
    actingAsRole('admin');

    $id = $this->postJson('/api/location-types', ['name' => ' Bodega  satélite ', 'description' => ''])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Bodega satélite')
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.locations_count', 0)
        ->json('data.id');

    $this->patchJson("/api/location-types/{$id}", ['description' => 'Fuera de la estación'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bodega satélite')
        ->assertJsonPath('data.description', 'Fuera de la estación');

    $this->getJson("/api/location-types/{$id}")->assertOk()->assertJsonPath('data.id', $id);
});

it('FR-006: nombre único sin distinguir mayúsculas, incluidos los inactivos', function () {
    actingAsRole('admin');
    LocationType::factory()->inactive()->create(['name' => 'Oficina']);
    $bodega = LocationType::factory()->create(['name' => 'Bodega']);

    $this->postJson('/api/location-types', ['name' => 'OFICINA'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
    $this->patchJson("/api/location-types/{$bodega->id}", ['name' => 'oficina'])->assertJsonValidationErrors('name');
    $this->postJson('/api/location-types', ['name' => ''])->assertJsonValidationErrors('name');
    $this->postJson('/api/location-types', ['name' => str_repeat('x', 121)])->assertJsonValidationErrors('name');
});

it('B-4: un nombre duplicado detectado por la base de datos responde 422', function () {
    actingAsRole('admin');
    LocationType::creating(function (LocationType $type) {
        DB::table('location_types')->insert(['name' => $type->name, 'created_at' => now(), 'updated_at' => now()]);
    });

    $this->postJson('/api/location-types', ['name' => 'Bodega'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
});

it('FR-008 / RN-003: desactivar no toca sus ubicaciones y no existe DELETE', function () {
    actingAsRole('admin');
    $type = LocationType::factory()->create();
    $location = Location::factory()->for($type, 'locationType')->create();

    $this->patchJson("/api/location-types/{$type->id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($location->fresh()->is_active)->toBeTrue();

    $this->deleteJson("/api/location-types/{$type->id}")->assertMethodNotAllowed();
});

it('RN-011: 401 sin sesión, 403 sin ver y 403 al modificar sin gestionar', function () {
    $type = LocationType::factory()->create();

    $this->getJson('/api/location-types')->assertUnauthorized();

    actingWithPermissions([]);
    $this->getJson('/api/location-types')->assertForbidden();
    $this->getJson("/api/location-types/{$type->id}")->assertForbidden();

    actingAsRole('consulta');
    $this->postJson('/api/location-types', [])->assertForbidden();
    $this->patchJson("/api/location-types/{$type->id}", ['name' => 'X'])->assertForbidden();
    $this->patchJson("/api/location-types/{$type->id}/status", ['is_active' => false])->assertForbidden();
});
