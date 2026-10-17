<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Helpers de RBAC (D-011)
|--------------------------------------------------------------------------
*/

/**
 * Siembra el catálogo de permisos y los roles precargados (RN-009, RN-010).
 * Los seeders son idempotentes: llamarlo varias veces no duplica nada.
 */
function seedRbac(): void
{
    test()->seed([PermissionSeeder::class, RoleSeeder::class]);
}

/**
 * Autentica un usuario nuevo con uno de los roles precargados
 * ('admin', 'almacenista', 'consulta').
 *
 * @param  array<string, mixed>  $attributes
 */
function actingAsRole(string $code, array $attributes = []): User
{
    seedRbac();

    $user = User::factory()->create([
        'role_id' => Role::query()->where('code', $code)->value('id'),
        ...$attributes,
    ]);

    test()->actingAs($user);

    return $user;
}

/**
 * Autentica un usuario nuevo con un rol ad hoc que tiene exactamente esos
 * permisos.
 *
 * @param  list<string>  $codes
 * @param  array<string, mixed>  $attributes
 */
function actingWithPermissions(array $codes, array $attributes = []): User
{
    seedRbac();

    $role = Role::factory()->create();
    $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));

    $user = User::factory()->create(['role_id' => $role->id, ...$attributes]);

    test()->actingAs($user);

    return $user;
}
