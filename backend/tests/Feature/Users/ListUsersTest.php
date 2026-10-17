<?php

use App\Models\Role;
use App\Models\User;

it('lista usuarios paginados con su rol', function () {
    actingAsRole('admin');
    User::factory()->count(3)->create();

    $this->getJson('/api/users?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 4)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonStructure([
            'data' => [['id', 'name', 'username', 'email', 'is_active', 'must_change_password', 'last_login_at', 'role' => ['id', 'name', 'code'], 'created_at']],
            'meta', 'links',
        ])
        ->assertJsonMissingPath('data.0.password');
});

it('busca por nombre, usuario o correo sin distinguir mayúsculas', function () {
    actingAsRole('admin', ['name' => 'Administrador', 'username' => 'admin1', 'email' => 'admin1@b31.co']);
    User::factory()->create(['name' => 'Juana Pérez', 'username' => 'jperez', 'email' => 'jp@b31.co']);
    User::factory()->create(['name' => 'Carlos Ruiz', 'username' => 'cruiz', 'email' => 'cr@b31.co']);

    foreach (['JUANA', 'jPeR', 'JP@B31'] as $search) {
        $this->getJson('/api/users?search='.urlencode($search))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.username', 'jperez');
    }
});

it('filtra por rol y por estado', function () {
    actingAsRole('admin');
    $consulta = Role::query()->where('code', 'consulta')->value('id');
    User::factory()->create(['role_id' => $consulta, 'username' => 'consulta1']);
    User::factory()->inactive()->create(['role_id' => $consulta, 'username' => 'consulta2']);

    $this->getJson("/api/users?role_id={$consulta}")->assertOk()->assertJsonCount(2, 'data');
    $this->getJson("/api/users?role_id={$consulta}&is_active=0")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.username', 'consulta2');
});

it('limita per_page a 100', function () {
    actingAsRole('admin');

    $this->getJson('/api/users?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('muestra un usuario', function () {
    actingAsRole('consulta');
    $user = User::factory()->create();

    $this->getJson("/api/users/{$user->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role.id', $user->role_id);
});

it('RN-009: niega listar y ver usuarios sin usuarios.ver', function () {
    actingWithPermissions(['activos.ver', 'activos.gestionar']);
    $user = User::factory()->create();

    $this->getJson('/api/users')->assertForbidden();
    $this->getJson("/api/users/{$user->id}")->assertForbidden();
});

it('responde 404 en español para un usuario inexistente', function () {
    actingAsRole('admin');

    $this->getJson('/api/users/999999')->assertNotFound()->assertJsonPath('message', 'Recurso no encontrado.');
});
