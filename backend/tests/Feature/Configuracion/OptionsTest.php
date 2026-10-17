<?php

use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\LocationType;

it('FR-010 / SC-005: devuelve solo opciones disponibles, ordenadas por nombre y sin paginar', function () {
    actingAsRole('consulta');

    $comunicaciones = AssetCategory::factory()->create(['name' => 'Comunicaciones']);
    $epp = AssetCategory::factory()->create(['name' => 'EPP']);
    $herramientas = AssetCategory::factory()->inactive()->create(['name' => 'Herramientas']);

    $radio = AssetType::factory()->for($comunicaciones, 'category')->create(['name' => 'Radio portátil', 'tracking_mode' => TrackingMode::Serialized]);
    $guantes = AssetType::factory()->for($epp, 'category')->create(['name' => 'Guantes de rescate', 'tracking_mode' => TrackingMode::Quantity]);
    AssetType::factory()->for($epp, 'category')->inactive()->create(['name' => 'Casco viejo']);
    AssetType::factory()->for($herramientas, 'category')->create(['name' => 'Motosierra']);

    $vehiculo = LocationType::factory()->create(['name' => 'Vehículo']);
    $bodega = LocationType::factory()->create(['name' => 'Bodega']);
    $oficina = LocationType::factory()->inactive()->create(['name' => 'Oficina']);

    $maquina = Location::factory()->for($vehiculo, 'locationType')->create(['name' => 'Máquina 1']);
    $principal = Location::factory()->for($bodega, 'locationType')->create(['name' => 'Bodega principal']);
    Location::factory()->for($bodega, 'locationType')->inactive()->create(['name' => 'Bodega vieja']);
    Location::factory()->for($oficina, 'locationType')->create(['name' => 'Recepción']);

    $this->getJson('/api/configuracion/options')
        ->assertOk()
        ->assertExactJson(['data' => [
            'asset_categories' => [
                ['id' => $comunicaciones->id, 'name' => 'Comunicaciones'],
                ['id' => $epp->id, 'name' => 'EPP'],
            ],
            'asset_types' => [
                ['id' => $guantes->id, 'name' => 'Guantes de rescate', 'asset_category_id' => $epp->id, 'tracking_mode' => 'quantity'],
                ['id' => $radio->id, 'name' => 'Radio portátil', 'asset_category_id' => $comunicaciones->id, 'tracking_mode' => 'serialized'],
            ],
            'location_types' => [
                ['id' => $bodega->id, 'name' => 'Bodega'],
                ['id' => $vehiculo->id, 'name' => 'Vehículo'],
            ],
            'locations' => [
                ['id' => $principal->id, 'name' => 'Bodega principal', 'location_type_id' => $bodega->id],
                ['id' => $maquina->id, 'name' => 'Máquina 1', 'location_type_id' => $vehiculo->id],
            ],
        ]]);
});

it('devuelve listas vacías si no hay catálogos', function () {
    actingAsRole('consulta');

    $this->getJson('/api/configuracion/options')
        ->assertOk()
        ->assertExactJson(['data' => ['asset_categories' => [], 'asset_types' => [], 'location_types' => [], 'locations' => []]]);
});

it('D-005 / RN-011: requiere configuracion.ver', function () {
    $this->getJson('/api/configuracion/options')->assertUnauthorized();

    actingWithPermissions(['activos.ver', 'activos.gestionar']);
    $this->getJson('/api/configuracion/options')->assertForbidden();

    actingWithPermissions(['configuracion.ver']);
    $this->getJson('/api/configuracion/options')->assertOk();
});
