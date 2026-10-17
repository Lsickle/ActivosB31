<?php

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

beforeEach(fn () => seedRbac());

it('concede los 13 permisos del catálogo al rol Administrador (RN-010)', function () {
    $admin = User::factory()->admin()->create();

    foreach (Permission::values() as $code) {
        expect(Gate::forUser($admin)->allows($code))->toBeTrue();
    }
});

it('M-5: sin Gate::before, el Administrador no recibe habilidades fuera del catálogo', function () {
    $admin = User::factory()->admin()->create();

    expect(Gate::forUser($admin)->allows('inexistente.ver'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('reportes.gestionar'))->toBeFalse();
});

it('evalúa cada permiso según el rol del usuario (RN-009)', function () {
    $consulta = User::factory()->create(['role_id' => Role::query()->where('code', 'consulta')->value('id')]);

    expect(Gate::forUser($consulta)->allows('activos.ver'))->toBeTrue()
        ->and(Gate::forUser($consulta)->allows('activos.gestionar'))->toBeFalse();
});

it('niega un permiso desconocido a quien no es administrador', function () {
    $consulta = User::factory()->create(['role_id' => Role::query()->where('code', 'consulta')->value('id')]);

    expect(Gate::forUser($consulta)->allows('reportes.gestionar'))->toBeFalse()
        ->and(Gate::forUser($consulta)->allows('inexistente.ver'))->toBeFalse();
});
