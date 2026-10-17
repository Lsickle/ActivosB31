<?php

use App\Enums\Permission as PermissionCode;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => seedRbac());

it('pertenece a exactamente un rol (RN-013)', function () {
    $role = Role::query()->where('code', 'consulta')->firstOrFail();
    $user = User::factory()->create(['role_id' => $role->id]);

    expect($user->role->is($role))->toBeTrue();
});

it('el administrador tiene los 13 permisos (RN-010)', function () {
    $user = User::factory()->admin()->create();

    expect($user->isAdmin())->toBeTrue()
        ->and($user->permissionCodes())->toEqualCanonicalizing(PermissionCode::values());
});

it('un usuario de Consulta solo tiene permisos de ver (RN-010)', function () {
    $user = User::factory()->create(['role_id' => Role::query()->where('code', 'consulta')->value('id')]);

    expect($user->isAdmin())->toBeFalse()
        ->and($user->hasPermission('activos.ver'))->toBeTrue()
        ->and($user->hasPermission(PermissionCode::ActivosGestionar))->toBeFalse()
        ->and($user->permissionCodes())->each->toEndWith('.ver');
});

it('un rol inactivo no concede permisos', function () {
    $role = Role::factory()->inactive()->create();
    $role->permissions()->sync(Permission::query()->pluck('id'));
    $user = User::factory()->create(['role_id' => $role->id]);

    expect($user->permissionCodes())->toBe([])
        ->and($user->hasPermission('activos.ver'))->toBeFalse();
});

it('memoriza los permisos por instancia (una consulta por request)', function () {
    $user = User::factory()->create(['role_id' => Role::query()->where('code', 'almacenista')->value('id')]);
    $user = $user->fresh();

    DB::enableQueryLog();
    $user->hasPermission('activos.ver');
    $user->hasPermission('activos.gestionar');
    $user->permissionCodes();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBeLessThanOrEqual(2);
});

it('guarda username y email en minúsculas (FR-008)', function () {
    $user = User::factory()->create(['username' => '  JPerez ', 'email' => 'JPerez@B31.CO']);

    expect($user->fresh())
        ->username->toBe('jperez')
        ->email->toBe('jperez@b31.co');
});

it('permite usuarios sin correo', function () {
    $user = User::factory()->create(['email' => null]);

    expect($user->fresh()->email)->toBeNull();
});

it('la base de datos rechaza usernames duplicados sin distinguir mayúsculas (FR-008)', function () {
    User::factory()->create(['username' => 'jperez']);

    expect(fn () => DB::table('users')->insert([
        'name' => 'Otro', 'username' => 'JPEREZ', 'password' => 'x',
        'role_id' => Role::query()->value('id'),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('la base de datos rechaza un username con arroba (FR-008)', function () {
    expect(fn () => DB::table('users')->insert([
        'name' => 'Otro', 'username' => 'a@b', 'password' => 'x',
        'role_id' => Role::query()->value('id'),
    ]))->toThrow(QueryException::class);
});

it('marca el cambio de contraseña pendiente con el estado de la factory (RN-014)', function () {
    expect(User::factory()->mustChangePassword()->create()->must_change_password)->toBeTrue()
        ->and(User::factory()->create()->must_change_password)->toBeFalse();
});
