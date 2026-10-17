<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

function seededRole(string $code): Role
{
    return Role::query()->where('code', $code)->firstOrFail();
}

it('RN-009: reemplaza el conjunto de permisos de un rol', function () {
    actingAsRole('admin');
    $consulta = seededRole('consulta');

    $this->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => ['empleados.ver', 'reportes.ver']])
        ->assertOk()
        ->assertJsonPath('data.id', $consulta->id)
        ->assertJsonPath('data.permissions', ['empleados.ver', 'reportes.ver']);

    expect($consulta->permissions()->pluck('code')->sort()->values()->all())->toBe(['empleados.ver', 'reportes.ver']);
});

it('FR-002: gestionar agrega ver del mismo módulo', function () {
    actingAsRole('admin');
    $consulta = seededRole('consulta');

    $this->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => ['empleados.gestionar']])
        ->assertOk()
        ->assertJsonPath('data.permissions', ['empleados.ver', 'empleados.gestionar']);
});

it('permite dejar un rol sin permisos', function () {
    actingAsRole('admin');
    $consulta = seededRole('consulta');

    $this->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => []])
        ->assertOk()
        ->assertJsonPath('data.permissions', []);
});

it('rechaza códigos de permiso desconocidos', function (?array $permissions) {
    actingAsRole('admin');
    $consulta = seededRole('consulta');

    $this->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => $permissions])
        ->assertUnprocessable();

    expect($consulta->permissions()->count())->toBe(7);
})->with([
    'inexistente' => [['activos.borrar']],
    'reportes.gestionar' => [['reportes.gestionar']],
    'sin lista' => [null],
]);

it('RN-010: los permisos del Administrador no se pueden modificar', function () {
    actingAsRole('admin');
    $admin = seededRole('admin');

    $this->putJson("/api/roles/{$admin->id}/permissions", ['permissions' => ['activos.ver']])
        ->assertForbidden()
        ->assertJsonPath('message', 'Los permisos del rol Administrador no se pueden modificar.');

    expect($admin->permissions()->count())->toBe(13);
});

it('RN-009: niega editar la matriz sin usuarios.gestionar', function () {
    actingAsRole('almacenista');
    $consulta = seededRole('consulta');

    $this->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => ['activos.gestionar']])
        ->assertForbidden();

    expect($consulta->permissions()->count())->toBe(7);
});

it('SC-003: el cambio en la matriz rige en la siguiente petición del usuario afectado', function () {
    seedRbac();
    $consulta = seededRole('consulta');
    $member = User::factory()->create(['role_id' => $consulta->id]);
    $memberToken = $member->createToken('android')->plainTextToken;
    $admin = User::factory()->admin()->create();

    $this->withToken($memberToken)->getJson('/api/users')->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withoutToken()->actingAs($admin)
        ->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => ['activos.ver']])
        ->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withToken($memberToken)->getJson('/api/users')->assertForbidden();
});

it('RN-019: la matriz no concede usuarios.gestionar a un rol distinto del Administrador', function () {
    actingAsRole('admin');
    $consulta = seededRole('consulta');
    $custom = Role::factory()->create();

    foreach ([$consulta, $custom] as $role) {
        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => ['usuarios.ver', 'usuarios.gestionar']])
            ->assertUnprocessable()
            ->assertJsonPath('errors.permissions.0', 'El permiso «Gestionar Usuarios y roles» es exclusivo del rol Administrador.');
    }

    expect($consulta->permissions()->where('code', 'usuarios.gestionar')->exists())->toBeFalse()
        ->and($custom->permissions()->count())->toBe(0);
});

it('RN-019: escalada: un usuario de un rol no admin no obtiene usuarios.gestionar', function () {
    $member = actingAsRole('consulta');
    $consulta = seededRole('consulta');

    // Ni siquiera si la fila llegara a existir en la base de datos.
    $consulta->permissions()->attach(Permission::query()->where('code', 'usuarios.gestionar')->value('id'));
    $member = $member->fresh();
    $this->actingAs($member);

    expect($member->hasPermission('usuarios.gestionar'))->toBeFalse();
    $this->postJson('/api/users', [
        'name' => 'Intruso', 'username' => 'intruso', 'role_id' => $consulta->id,
        'password' => 'temporal-123', 'password_confirmation' => 'temporal-123',
    ])->assertForbidden();
    $this->putJson("/api/roles/{$consulta->id}/permissions", ['permissions' => ['usuarios.gestionar']])->assertForbidden();
    expect($this->getJson('/api/user')->assertOk()->json('user.permissions'))->not->toContain('usuarios.gestionar');
});
