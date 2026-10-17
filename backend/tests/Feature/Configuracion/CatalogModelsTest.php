<?php

use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\LocationType;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('crea las cuatro tablas de Configuración sin softDeletes (RN-003)', function () {
    foreach (['asset_categories', 'asset_types', 'location_types', 'locations'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue()
            ->and(Schema::hasColumn($table, 'deleted_at'))->toBeFalse()
            ->and(Schema::hasColumns($table, ['id', 'name', 'description', 'is_active', 'created_at', 'updated_at']))->toBeTrue();
    }

    // RN-002: lista plana de ubicaciones, sin ubicación padre.
    expect(Schema::hasColumn('locations', 'parent_id'))->toBeFalse();
});

it('FR-006: la unicidad de nombre no distingue mayúsculas en categorías, tipos de ubicación y ubicaciones', function () {
    AssetCategory::factory()->create(['name' => 'EPP']);
    expect(fn () => inSavepoint(fn () => AssetCategory::factory()->create(['name' => 'epp'])))
        ->toThrow(UniqueConstraintViolationException::class, 'asset_categories_name_lower_unique');

    LocationType::factory()->create(['name' => 'Bodega']);
    expect(fn () => inSavepoint(fn () => LocationType::factory()->create(['name' => 'BODEGA'])))
        ->toThrow(UniqueConstraintViolationException::class, 'location_types_name_lower_unique');

    Location::factory()->create(['name' => 'Máquina 1']);
    expect(fn () => inSavepoint(fn () => Location::factory()->create(['name' => 'MÁQUINA 1'])))
        ->toThrow(UniqueConstraintViolationException::class, 'locations_name_lower_unique');
});

it('FR-006: los tipos de activo son únicos por categoría sin distinguir mayúsculas', function () {
    $epp = AssetCategory::factory()->create();
    $otra = AssetCategory::factory()->create();
    AssetType::factory()->for($epp, 'category')->create(['name' => 'Guantes de rescate']);

    AssetType::factory()->for($otra, 'category')->create(['name' => 'Guantes de rescate']);

    expect(fn () => inSavepoint(fn () => AssetType::factory()->for($epp, 'category')->create(['name' => 'GUANTES DE RESCATE'])))
        ->toThrow(UniqueConstraintViolationException::class, 'asset_types_category_name_lower_unique');
});

it('RN-007: tracking_mode solo admite serialized o quantity y se castea al enum', function () {
    $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);

    expect($type->fresh()->tracking_mode)->toBe(TrackingMode::Quantity)
        ->and(TrackingMode::Serialized->label())->toBe('Serializado')
        ->and(TrackingMode::Quantity->label())->toBe('Por cantidad');

    expect(fn () => inSavepoint(fn () => DB::table('asset_types')->where('id', $type->id)->update(['tracking_mode' => 'otro'])))
        ->toThrow(QueryException::class);
});

it('D-010: las FK son restrict: no se borra una categoría con tipos ni un tipo de ubicación con ubicaciones', function () {
    $type = AssetType::factory()->create();
    $location = Location::factory()->create();

    expect(fn () => inSavepoint(fn () => DB::table('asset_categories')->where('id', $type->asset_category_id)->delete()))
        ->toThrow(QueryException::class);
    expect(fn () => inSavepoint(fn () => DB::table('location_types')->where('id', $location->location_type_id)->delete()))
        ->toThrow(QueryException::class);
});

it('scope active() devuelve solo registros activos', function () {
    AssetCategory::factory()->create(['name' => 'Activa']);
    AssetCategory::factory()->inactive()->create(['name' => 'Inactiva']);

    expect(AssetCategory::query()->active()->pluck('name')->all())->toBe(['Activa']);
});

it('FR-008: available() excluye tipos de activo de categorías inactivas sin desactivarlos', function () {
    $activa = AssetCategory::factory()->create();
    $inactiva = AssetCategory::factory()->inactive()->create();
    $disponible = AssetType::factory()->for($activa, 'category')->create();
    AssetType::factory()->for($activa, 'category')->inactive()->create();
    $huerfano = AssetType::factory()->for($inactiva, 'category')->create();

    expect(AssetType::query()->available()->pluck('id')->all())->toBe([$disponible->id])
        ->and($huerfano->fresh()->is_active)->toBeTrue();
});

it('FR-008: available() excluye ubicaciones de tipos de ubicación inactivos', function () {
    $activo = LocationType::factory()->create();
    $inactivo = LocationType::factory()->inactive()->create();
    $disponible = Location::factory()->for($activo, 'locationType')->create();
    Location::factory()->for($activo, 'locationType')->inactive()->create();
    Location::factory()->for($inactivo, 'locationType')->create();

    expect(Location::query()->available()->pluck('id')->all())->toBe([$disponible->id]);
});

it('D-003: search() no distingue mayúsculas ni tildes y escapa comodines', function () {
    AssetCategory::factory()->create(['name' => 'Equipos de extinción']);
    AssetCategory::factory()->create(['name' => 'Comunicaciones']);
    AssetCategory::factory()->create(['name' => 'Señalización 100%']);

    expect(AssetCategory::query()->search('EXTINCION')->pluck('name')->all())->toBe(['Equipos de extinción'])
        ->and(AssetCategory::query()->search('senal')->pluck('name')->all())->toBe(['Señalización 100%'])
        ->and(AssetCategory::query()->search('Comunicación')->pluck('name')->all())->toBe(['Comunicaciones'])
        ->and(AssetCategory::query()->search('%')->pluck('name')->all())->toBe(['Señalización 100%'])
        ->and(AssetCategory::query()->search('_')->count())->toBe(0);
});

it('D-009: hasAssets() devuelve false mientras no exista el registro de activos', function () {
    expect(AssetType::factory()->create()->hasAssets())->toBeFalse();
});

it('relaciones: categoría ↔ tipos y tipo de ubicación ↔ ubicaciones', function () {
    $category = AssetCategory::factory()->has(AssetType::factory()->count(2), 'assetTypes')->create();
    $locationType = LocationType::factory()->has(Location::factory()->count(3))->create();

    expect($category->assetTypes)->toHaveCount(2)
        ->and($category->assetTypes->first()->category->is($category))->toBeTrue()
        ->and($locationType->locations)->toHaveCount(3)
        ->and($locationType->locations->first()->locationType->is($locationType))->toBeTrue();
});
