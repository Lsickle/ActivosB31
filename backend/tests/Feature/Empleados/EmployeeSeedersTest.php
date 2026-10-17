<?php

use App\Models\Company;
use App\Models\Position;
use App\Models\Role;
use Database\Seeders\ConfigurationSeeder;

it('RN-035: precarga los cargos en el orden confirmado y ninguna compañía (RN-036)', function () {
    $this->seed(ConfigurationSeeder::class);

    expect(Position::query()->orderBy('id')->pluck('name')->all())->toBe([
        'Comandante', 'Capitán', 'Teniente', 'Subteniente', 'Sargento', 'Cabo', 'Bombero', 'Administrativo',
    ])
        ->and(Position::query()->where('is_active', false)->exists())->toBeFalse()
        ->and(Company::query()->count())->toBe(0);
});

it('RN-035 / D-008: los cargos se cargan solo si la tabla está vacía', function () {
    $this->seed(ConfigurationSeeder::class);
    Position::query()->where('name', 'Cabo')->update(['name' => 'Cabo primero', 'is_active' => false]);

    $this->seed(ConfigurationSeeder::class);

    expect(Position::query()->count())->toBe(8)
        ->and(Position::query()->where('name', 'Cabo')->exists())->toBeFalse();
});

it('RN-035 / D-008: no precarga cargos si ya existe alguno', function () {
    Position::factory()->create(['name' => 'Voluntario']);

    $this->seed(ConfigurationSeeder::class);

    expect(Position::query()->pluck('name')->all())->toBe(['Voluntario']);
});

it('RN-040: Consulta se precarga con todos los permisos de ver salvo empleados.ver', function () {
    seedRbac();

    $codes = Role::query()->where('code', 'consulta')->firstOrFail()->permissions()->pluck('code')->all();

    expect($codes)->toEqualCanonicalizing([
        'activos.ver', 'inventario.ver', 'entregas.ver', 'reportes.ver', 'configuracion.ver', 'usuarios.ver',
    ]);
});
