<?php

use App\Models\Company;
use App\Models\Position;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * FR-004 / D-001: cargos (RN-035) y compañías o turnos (RN-036) son
 * catálogos de Configuración con el patrón de 002.
 */
dataset('catalogos de empleados', [
    'cargos' => [Position::class, '/api/positions', 'positions'],
    'compañías' => [Company::class, '/api/companies', 'companies'],
]);

it('FR-004: lista paginada por nombre, con búsqueda sin tildes y filtro de estado', function (string $model, string $url) {
    actingAsRole('consulta');
    $model::factory()->create(['name' => 'Subteniente']);
    $model::factory()->create(['name' => 'Capitán']);
    $model::factory()->inactive()->create(['name' => 'Capitán retirado']);

    $this->getJson($url)
        ->assertOk()
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.name', 'Capitán')
        ->assertJsonStructure(['data' => [['id', 'name', 'description', 'is_active', 'created_at', 'updated_at']], 'meta', 'links']);

    $this->getJson("{$url}?search=CAPITAN&is_active=1")
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Capitán');
    $this->getJson("{$url}?is_active=0")->assertJsonCount(1, 'data');
})->with('catalogos de empleados');

it('FR-004: crea activo, normaliza espacios y consulta el detalle', function (string $model, string $url) {
    actingAsRole('admin');

    $id = $this->postJson($url, ['name' => '  Turno   noche ', 'description' => '  '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Turno noche')
        ->assertJsonPath('data.description', null)
        ->assertJsonPath('data.is_active', true)
        ->json('data.id');

    $this->getJson("{$url}/{$id}")->assertOk()->assertJsonPath('data.name', 'Turno noche');
})->with('catalogos de empleados');

it('FR-004: el nombre es único sin distinguir mayúsculas, también al editar', function (string $model, string $url) {
    actingAsRole('admin');
    $model::factory()->create(['name' => 'Teniente']);
    $otro = $model::factory()->create(['name' => 'Cabo']);

    $this->postJson($url, ['name' => 'TENIENTE'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
    $this->patchJson("{$url}/{$otro->id}", ['name' => 'teniente'])->assertJsonValidationErrors('name');
    $this->patchJson("{$url}/{$otro->id}", ['name' => 'CABO'])->assertOk()->assertJsonPath('data.name', 'CABO');
})->with('catalogos de empleados');

it('B-4: un nombre duplicado detectado por la base de datos responde 422', function (string $model, string $url, string $table) {
    actingAsRole('admin');
    $model::creating(function (Model $record) use ($table) {
        DB::table($table)->insert(['name' => $record->name, 'created_at' => now(), 'updated_at' => now()]);
    });

    $this->postJson($url, ['name' => 'Bombero'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
})->with('catalogos de empleados');

it('RN-003: desactiva y reactiva; no existe DELETE (405)', function (string $model, string $url) {
    actingAsRole('admin');
    $record = $model::factory()->create();

    $this->patchJson("{$url}/{$record->id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);
    $this->patchJson("{$url}/{$record->id}/status", ['is_active' => true])
        ->assertJsonPath('data.is_active', true);

    $this->deleteJson("{$url}/{$record->id}")->assertMethodNotAllowed();
})->with('catalogos de empleados');

it('RN-011: 401 sin sesión, 403 sin ver y 403 al modificar sin gestionar', function (string $model, string $url) {
    $record = $model::factory()->create();

    $this->getJson($url)->assertUnauthorized();

    actingWithPermissions(['empleados.ver', 'empleados.gestionar']);
    $this->getJson($url)->assertForbidden();
    $this->getJson("{$url}/{$record->id}")->assertForbidden();

    actingAsRole('almacenista');
    $this->getJson("{$url}/{$record->id}")->assertOk();
    $this->postJson($url, ['name' => 'Nuevo'])->assertForbidden();
    $this->patchJson("{$url}/{$record->id}", ['name' => 'X'])->assertForbidden();
    $this->patchJson("{$url}/{$record->id}/status", ['is_active' => false])->assertForbidden();
})->with('catalogos de empleados');
