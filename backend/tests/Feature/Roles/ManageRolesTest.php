<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;

it('RN-010: crea un rol nuevo sin permisos', function () {
    actingAsRole('admin');

    $this->postJson('/api/roles', ['name' => 'Comandante', 'description' => 'Jefe de turno'])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Comandante')
        ->assertJsonPath('data.description', 'Jefe de turno')
        ->assertJsonPath('data.code', null)
        ->assertJsonPath('data.is_system', false)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.users_count', 0)
        ->assertJsonPath('data.permissions', []);
});

it('no permite fijar código ni marcar el rol como del sistema', function () {
    actingAsRole('admin');

    $id = $this->postJson('/api/roles', ['name' => 'Comandante', 'code' => 'admin2', 'is_system' => true])
        ->assertCreated()
        ->json('data.id');

    expect(Role::query()->findOrFail($id))->code->toBeNull()->is_system->toBeFalse();
});

it('exige un nombre único sin distinguir mayúsculas', function () {
    actingAsRole('admin');

    $this->postJson('/api/roles', ['name' => 'ADMINISTRADOR'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');

    $this->postJson('/api/roles', ['name' => ''])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.');

    $this->postJson('/api/roles', ['name' => str_repeat('a', 81)])->assertJsonValidationErrors('name');
});

it('edita nombre y descripción', function () {
    actingAsRole('admin');
    $role = Role::factory()->create(['name' => 'Comandante']);

    $this->patchJson("/api/roles/{$role->id}", ['name' => 'COMANDANTE', 'description' => null])
        ->assertOk()
        ->assertJsonPath('data.name', 'COMANDANTE')
        ->assertJsonPath('data.description', null);

    $this->patchJson("/api/roles/{$role->id}", ['name' => 'consulta'])->assertJsonValidationErrors('name');
});

it('RN-020: los roles precargados no se renombran pero sí cambian su descripción', function () {
    actingAsRole('admin');
    $almacenista = Role::query()->where('code', 'almacenista')->firstOrFail();

    $this->patchJson("/api/roles/{$almacenista->id}", ['name' => 'Bodeguero'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'Los roles precargados no se pueden renombrar.');

    $this->patchJson("/api/roles/{$almacenista->id}", ['name' => 'Almacenista', 'description' => 'Bodega central'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Almacenista')
        ->assertJsonPath('data.description', 'Bodega central');
});

it('B-4: un nombre duplicado detectado por la base de datos responde 422', function () {
    actingAsRole('admin');
    Role::creating(function (Role $role) {
        DB::table('roles')->insert(['name' => $role->name, 'created_at' => now(), 'updated_at' => now()]);
    });

    $this->postJson('/api/roles', ['name' => 'Comandante'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
});

it('FR-013: los roles precargados no se pueden desactivar', function () {
    actingAsRole('admin');
    $almacenista = Role::query()->where('code', 'almacenista')->firstOrFail();

    $this->patchJson("/api/roles/{$almacenista->id}/status", ['is_active' => false])
        ->assertConflict()
        ->assertJsonPath('message', 'Los roles precargados no se pueden desactivar.');
});

it('FR-013: un rol con usuarios activos no se puede desactivar', function () {
    actingAsRole('admin');
    $role = Role::factory()->create();
    User::factory()->count(2)->create(['role_id' => $role->id]);
    User::factory()->inactive()->create(['role_id' => $role->id]);

    $this->patchJson("/api/roles/{$role->id}/status", ['is_active' => false])
        ->assertConflict()
        ->assertJsonPath('message', 'El rol tiene 2 usuarios activos asignados.');

    expect($role->fresh()->is_active)->toBeTrue();
});

it('desactiva y reactiva un rol sin usuarios activos', function () {
    actingAsRole('admin');
    $role = Role::factory()->create();
    User::factory()->inactive()->create(['role_id' => $role->id]);

    $this->patchJson("/api/roles/{$role->id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    $this->patchJson("/api/roles/{$role->id}/status", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('RN-009: niega crear, editar o cambiar estado de roles sin usuarios.gestionar', function () {
    actingAsRole('almacenista');
    $role = Role::factory()->create(['name' => 'Comandante']);

    $this->postJson('/api/roles', ['name' => 'Otro'])->assertForbidden();
    $this->patchJson("/api/roles/{$role->id}", ['name' => 'Cambiado'])->assertForbidden();
    $this->patchJson("/api/roles/{$role->id}/status", ['is_active' => false])->assertForbidden();

    expect($role->fresh())->name->toBe('Comandante')->is_active->toBeTrue();
});
