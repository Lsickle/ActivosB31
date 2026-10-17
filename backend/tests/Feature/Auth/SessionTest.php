<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;

it('responde 401 al pedir el usuario sin autenticar', function () {
    $this->getJson('/api/user')->assertUnauthorized();
});

it('responde 401 y no un error de ruta cuando no se pide JSON', function () {
    $this->get('/api/user')->assertUnauthorized();
});

it('devuelve el usuario autenticado con su rol y permisos efectivos (FR-014)', function () {
    $user = actingAsRole('consulta');

    $this->getJson('/api/user')
        ->assertOk()
        ->assertJsonPath('user.username', $user->username)
        ->assertJsonPath('user.role.code', 'consulta')
        ->assertJsonPath('user.role.name', 'Consulta')
        ->assertJsonPath('user.role.id', $user->role_id)
        ->assertJsonPath('user.permissions', [
            // RN-040: Consulta no tiene empleados.ver en la precarga.
            'activos.ver', 'inventario.ver', 'entregas.ver',
            'reportes.ver', 'configuracion.ver', 'usuarios.ver',
        ])
        ->assertJsonPath('user.must_change_password', false)
        ->assertJsonMissingPath('user.password')
        ->assertJsonMissingPath('user.role.permissions');
});

it('informa los 13 permisos al administrador (FR-014, RN-010)', function () {
    actingAsRole('admin');

    expect($this->getJson('/api/user')->assertOk()->json('user.permissions'))
        ->toEqualCanonicalizing(Permission::values());
});

it('el login devuelve el mismo usuario con rol, permisos y cambio pendiente', function () {
    seedRbac();
    User::factory()->mustChangePassword()->create([
        'username' => 'nuevo',
        'password' => 'secreto-123',
        'role_id' => Role::query()->where('code', 'almacenista')->value('id'),
    ]);

    $this->postJson('/api/login', ['login' => 'nuevo', 'password' => 'secreto-123', 'device_name' => 'test'])
        ->assertOk()
        ->assertJsonPath('user.role.code', 'almacenista')
        ->assertJsonPath('user.must_change_password', true)
        ->assertJsonCount(10, 'user.permissions');
});

it('bloquea la API a una cuenta desactivada con sesión ya iniciada', function () {
    $user = User::factory()->inactive()->create();

    $this->actingAs($user)->getJson('/api/user')->assertForbidden();
});

it('revoca el token Bearer al cerrar sesión', function () {
    $user = User::factory()->create();
    $token = $user->createToken('android')->plainTextToken;

    $this->withToken($token)->postJson('/api/logout')->assertOk();

    expect($user->tokens()->count())->toBe(0);
});
