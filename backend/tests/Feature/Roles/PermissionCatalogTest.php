<?php

use App\Models\Role;
use App\Models\User;

it('RN-009: entrega el catálogo de permisos agrupado por módulo', function () {
    actingAsRole('consulta');

    $response = $this->getJson('/api/permissions')->assertOk()->assertJsonCount(7, 'data');

    expect($response->json('data.0'))->toBe([
        'module' => 'activos',
        'label' => 'Activos',
        'permissions' => [
            ['code' => 'activos.ver', 'action' => 'ver'],
            ['code' => 'activos.gestionar', 'action' => 'gestionar'],
        ],
    ])->and($response->json('data.4'))->toBe([
        'module' => 'reportes',
        'label' => 'Reportes',
        'permissions' => [['code' => 'reportes.ver', 'action' => 'ver']],
    ])->and(collect($response->json('data'))->pluck('module')->all())->toBe([
        'activos', 'inventario', 'entregas', 'empleados', 'reportes', 'configuracion', 'usuarios',
    ]);
});

it('lista los roles con sus permisos y cantidad de usuarios activos (RN-016)', function () {
    actingAsRole('admin');
    User::factory()->count(2)->create(['role_id' => Role::query()->where('code', 'consulta')->value('id')]);
    User::factory()->inactive()->create(['role_id' => Role::query()->where('code', 'consulta')->value('id')]);
    User::factory()->admin()->inactive()->create();

    $response = $this->getJson('/api/roles')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonStructure(['data' => [['id', 'code', 'name', 'description', 'is_system', 'is_active', 'users_count', 'permissions']]]);

    $roles = collect($response->json('data'))->keyBy('code');
    expect($roles['admin']['permissions'])->toHaveCount(13)
        ->and($roles['admin']['users_count'])->toBe(1)
        ->and($roles['consulta']['users_count'])->toBe(2)
        ->and($roles['consulta']['permissions'])->toContain('activos.ver')
        ->and($roles['almacenista']['is_system'])->toBeTrue();
});

it('filtra roles por búsqueda y estado', function () {
    actingAsRole('admin');
    Role::factory()->inactive()->create(['name' => 'Comandante']);

    $this->getJson('/api/roles?search=coman')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Comandante');
    $this->getJson('/api/roles?is_active=1')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/roles?is_active=0')->assertOk()->assertJsonCount(1, 'data');
});

it('RN-009: niega el catálogo y los roles sin usuarios.ver', function () {
    actingWithPermissions(['activos.ver']);

    $this->getJson('/api/permissions')->assertForbidden();
    $this->getJson('/api/roles')->assertForbidden();
});
