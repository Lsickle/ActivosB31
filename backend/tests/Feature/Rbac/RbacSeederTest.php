<?php

use App\Enums\Permission as PermissionCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * @return list<string>
 */
function roleCodes(string $code): array
{
    return Role::query()->where('code', $code)->firstOrFail()
        ->permissions()->pluck('code')->sort()->values()->all();
}

it('siembra el catálogo de 13 permisos', function () {
    seedRbac();

    expect(Permission::query()->pluck('code')->all())->toEqualCanonicalizing(PermissionCode::values());
});

it('precarga Administrador, Almacenista y Consulta como roles del sistema (RN-010)', function () {
    seedRbac();

    expect(Role::query()->orderBy('id')->get(['code', 'name', 'is_system', 'is_active'])->toArray())->toBe([
        ['code' => 'admin', 'name' => 'Administrador', 'is_system' => true, 'is_active' => true],
        ['code' => 'almacenista', 'name' => 'Almacenista', 'is_system' => true, 'is_active' => true],
        ['code' => 'consulta', 'name' => 'Consulta', 'is_system' => true, 'is_active' => true],
    ]);
});

it('asigna los permisos iniciales de cada rol (FR-003, RN-010, RN-011)', function () {
    seedRbac();

    expect(roleCodes('admin'))->toEqualCanonicalizing(PermissionCode::values())
        ->and(roleCodes('almacenista'))->toEqualCanonicalizing([
            'activos.ver', 'activos.gestionar',
            'inventario.ver', 'inventario.gestionar',
            'entregas.ver', 'entregas.gestionar',
            'empleados.ver', 'reportes.ver', 'configuracion.ver', 'usuarios.ver',
        ])
        ->and(roleCodes('consulta'))->toEqualCanonicalizing([
            'activos.ver', 'inventario.ver', 'entregas.ver', 'empleados.ver',
            'reportes.ver', 'configuracion.ver', 'usuarios.ver',
        ]);
});

it('es idempotente', function () {
    seedRbac();
    seedRbac();

    expect(Permission::query()->count())->toBe(13)
        ->and(Role::query()->count())->toBe(3);
});

it('no pisa los cambios hechos desde la matriz a un rol precargado (RN-010)', function () {
    seedRbac();
    $almacenista = Role::query()->where('code', 'almacenista')->firstOrFail();
    $almacenista->permissions()->sync(Permission::query()->where('code', 'activos.ver')->pluck('id'));

    seedRbac();

    expect(roleCodes('almacenista'))->toBe(['activos.ver']);
});

it('re-sincroniza siempre al Administrador con todos los permisos (RN-010)', function () {
    seedRbac();
    Role::query()->where('code', 'admin')->firstOrFail()->permissions()->detach();

    seedRbac();

    expect(roleCodes('admin'))->toHaveCount(13);
});

it('M-3: crea el usuario admin con el rol Administrador y cambio de contraseña obligatorio (FR-004, RN-014)', function () {
    config(['app.admin_initial_password' => 'clave-inicial-123']);

    $this->seed(DatabaseSeeder::class);

    $admin = User::query()->where('username', 'admin')->firstOrFail();
    expect($admin->role->code)->toBe('admin')
        ->and($admin->must_change_password)->toBeTrue();
});

it('M-3: re-sembrar no toca a los administradores existentes', function () {
    config(['app.admin_initial_password' => 'clave-inicial-123']);
    $this->seed(DatabaseSeeder::class);
    $admin = User::query()->where('username', 'admin')->firstOrFail();
    $admin->forceFill(['must_change_password' => false, 'password' => 'otra-clave-456'])->save();

    $this->seed(DatabaseSeeder::class);

    $admin->refresh();
    expect($admin->must_change_password)->toBeFalse()
        ->and(Hash::check('otra-clave-456', $admin->password))->toBeTrue()
        ->and(User::query()->count())->toBe(1);
});

it('RN-019: ningún rol distinto del Administrador queda con usuarios.gestionar', function () {
    seedRbac();
    $almacenista = Role::query()->where('code', 'almacenista')->firstOrFail();
    $custom = Role::factory()->create();
    $manage = Permission::query()->where('code', 'usuarios.gestionar')->value('id');
    $almacenista->permissions()->attach($manage);
    $custom->permissions()->attach($manage);

    seedRbac();

    expect(Permission::query()->findOrFail($manage)->roles()->pluck('code')->all())->toBe(['admin']);
});
