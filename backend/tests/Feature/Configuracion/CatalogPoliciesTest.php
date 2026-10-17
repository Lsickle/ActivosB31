<?php

use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\LocationType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

dataset('catalogos', [
    'categorías' => [AssetCategory::class],
    'tipos de activo' => [AssetType::class],
    'tipos de ubicación' => [LocationType::class],
    'ubicaciones' => [Location::class],
]);

/**
 * @param  class-string<Model>  $model
 * @return array<string, bool>
 */
function catalogAbilities(User $user, string $model): array
{
    $record = $model::factory()->create();

    return [
        'viewAny' => $user->can('viewAny', $model),
        'view' => $user->can('view', $record),
        'create' => $user->can('create', $model),
        'update' => $user->can('update', $record),
        'updateStatus' => $user->can('updateStatus', $record),
    ];
}

it('RN-011: el Administrador consulta y gestiona', function (string $model) {
    $user = actingAsRole('admin');

    expect(catalogAbilities($user, $model))->each->toBeTrue();
})->with('catalogos');

it('RN-011: Consulta y Almacenista solo consultan', function (string $model, string $role) {
    $user = actingAsRole($role);

    expect(catalogAbilities($user, $model))->toBe([
        'viewAny' => true,
        'view' => true,
        'create' => false,
        'update' => false,
        'updateStatus' => false,
    ]);
})->with('catalogos')->with(['consulta', 'almacenista']);

it('RN-009: los permisos se evalúan por código, no por rol', function (string $model) {
    $gestor = actingWithPermissions(['configuracion.ver', 'configuracion.gestionar']);
    expect(catalogAbilities($gestor, $model))->each->toBeTrue();

    $sinPermisos = actingWithPermissions([]);
    expect(catalogAbilities($sinPermisos, $model))->each->toBeFalse();
})->with('catalogos');
