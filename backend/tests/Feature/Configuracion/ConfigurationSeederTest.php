<?php

use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\LocationType;
use Database\Seeders\ConfigurationSeeder;
use Database\Seeders\DatabaseSeeder;

it('RN-012: precarga 9 categorías, 34 tipos de activo, 4 tipos de ubicación y ninguna ubicación', function () {
    $this->seed(ConfigurationSeeder::class);

    expect(AssetCategory::query()->count())->toBe(9)
        ->and(AssetType::query()->count())->toBe(34)
        ->and(LocationType::query()->orderBy('name')->pluck('name')->all())->toBe(['Bodega', 'Oficina', 'Otro', 'Vehículo'])
        ->and(Location::query()->count())->toBe(0)
        ->and(AssetCategory::query()->where('is_active', false)->exists())->toBeFalse()
        ->and(AssetType::query()->whereNotNull('description')->exists())->toBeFalse();
});

it('RN-012: asocia cada tipo a su categoría con el nombre completo de la spec', function () {
    $this->seed(ConfigurationSeeder::class);

    $epp = AssetCategory::query()->where('name', 'Equipos de protección personal (EPP)')->firstOrFail();

    expect($epp->assetTypes()->orderBy('id')->pluck('name')->all())->toBe([
        'Casco estructural', 'Chaqueta estructural', 'Pantalón estructural', 'Botas estructurales',
        'Guantes estructurales', 'Monja / capucha', 'Guantes de rescate',
    ]);

    $extincion = AssetCategory::query()->where('name', 'Equipos de extinción')->firstOrFail();
    expect($extincion->assetTypes()->pluck('name')->all())->toContain('Manguera 1½"', 'Manguera 2½"');
});

it('RN-007: precarga el modo de control de cada tipo según la spec', function () {
    $this->seed(ConfigurationSeeder::class);

    $mode = fn (string $name) => AssetType::query()->where('name', $name)->sole()->tracking_mode;

    expect($mode('Casco estructural'))->toBe(TrackingMode::Serialized)
        ->and($mode('Botas estructurales'))->toBe(TrackingMode::Quantity)
        ->and($mode('Cuerdas de rescate'))->toBe(TrackingMode::Serialized)
        ->and($mode('Herramientas manuales de entrada forzada'))->toBe(TrackingMode::Quantity)
        ->and($mode('Extintor portátil'))->toBe(TrackingMode::Serialized)
        ->and($mode('Insumos médicos'))->toBe(TrackingMode::Quantity)
        ->and(AssetType::query()->where('tracking_mode', TrackingMode::Serialized)->count())->toBe(19)
        ->and(AssetType::query()->where('tracking_mode', TrackingMode::Quantity)->count())->toBe(15);
});

it('FR-014: repetir la carga no duplica registros', function () {
    $this->seed(ConfigurationSeeder::class);
    $this->seed(ConfigurationSeeder::class);

    expect(AssetCategory::query()->count())->toBe(9)
        ->and(AssetType::query()->count())->toBe(34)
        ->and(LocationType::query()->count())->toBe(4);
});

it('D-008: no recrea una categoría renombrada ni un tipo de ubicación desactivado', function () {
    $this->seed(ConfigurationSeeder::class);
    AssetCategory::query()->where('name', 'Comunicaciones')->update(['name' => 'Radiocomunicaciones']);
    LocationType::query()->where('name', 'Otro')->update(['is_active' => false]);

    $this->seed(ConfigurationSeeder::class);

    expect(AssetCategory::query()->where('name', 'Comunicaciones')->exists())->toBeFalse()
        ->and(AssetCategory::query()->count())->toBe(9)
        ->and(LocationType::query()->where('name', 'Otro')->value('is_active'))->toBeFalse();
});

it('D-008: carga cada grupo de forma independiente según su tabla', function () {
    LocationType::factory()->create(['name' => 'Patio']);

    $this->seed(ConfigurationSeeder::class);

    expect(AssetCategory::query()->count())->toBe(9)
        ->and(LocationType::query()->pluck('name')->all())->toBe(['Patio']);
});

it('D-008: DatabaseSeeder ejecuta la precarga de Configuración', function () {
    config(['app.admin_initial_password' => 'clave-inicial-123']);

    $this->seed(DatabaseSeeder::class);

    expect(AssetCategory::query()->count())->toBe(9)
        ->and(LocationType::query()->count())->toBe(4);
});
