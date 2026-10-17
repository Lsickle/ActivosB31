<?php

use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Role;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Support\Facades\Validator;

function passesUnique(string $value, UniqueCaseInsensitive $rule): bool
{
    return Validator::make(['name' => $value], ['name' => [$rule]])->passes();
}

it('sin alcance mantiene la unicidad global (uso de 001-rbac)', function () {
    $role = Role::factory()->create(['name' => 'Comandante']);

    expect(passesUnique('COMANDANTE', new UniqueCaseInsensitive('roles', 'name')))->toBeFalse()
        ->and(passesUnique('COMANDANTE', new UniqueCaseInsensitive('roles', 'name', $role->id)))->toBeTrue()
        ->and(passesUnique('Otro', new UniqueCaseInsensitive('roles', 'name')))->toBeTrue();
});

it('D-007: con alcance solo compara dentro de las columnas indicadas', function () {
    $epp = AssetCategory::factory()->create();
    $otra = AssetCategory::factory()->create();
    $type = AssetType::factory()->for($epp, 'category')->create(['name' => 'Guantes de rescate']);

    expect(passesUnique('guantes de rescate', new UniqueCaseInsensitive('asset_types', 'name', scope: ['asset_category_id' => $epp->id])))->toBeFalse()
        ->and(passesUnique('guantes de rescate', new UniqueCaseInsensitive('asset_types', 'name', scope: ['asset_category_id' => $otra->id])))->toBeTrue()
        ->and(passesUnique('guantes de rescate', new UniqueCaseInsensitive('asset_types', 'name', $type->id, ['asset_category_id' => $epp->id])))->toBeTrue();
});
