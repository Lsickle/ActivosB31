<?php

use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Policies\AssetTypePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

describe('listado', function () {
    it('FR-013: pagina y ordena por nombre, con su categoría y modo de control', function () {
        actingAsRole('consulta');
        $epp = AssetCategory::factory()->create(['name' => 'EPP']);
        AssetType::factory()->for($epp, 'category')->create(['name' => 'Guantes de rescate', 'tracking_mode' => TrackingMode::Quantity]);
        AssetType::factory()->for($epp, 'category')->create(['name' => 'Casco estructural', 'tracking_mode' => TrackingMode::Serialized]);

        $this->getJson('/api/asset-types')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 25)
            ->assertJsonPath('data.0.name', 'Casco estructural')
            ->assertJsonPath('data.0.tracking_mode', 'serialized')
            ->assertJsonPath('data.0.tracking_mode_label', 'Serializado')
            ->assertJsonPath('data.1.tracking_mode_label', 'Por cantidad')
            ->assertJsonPath('data.0.category', ['id' => $epp->id, 'name' => 'EPP', 'is_active' => true])
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'tracking_mode', 'tracking_mode_label', 'is_active', 'category' => ['id', 'name', 'is_active'], 'created_at', 'updated_at']], 'meta', 'links']);
    });

    it('FR-002: filtra por categoría, modo de control, estado y búsqueda sin tildes', function () {
        actingAsRole('consulta');
        $epp = AssetCategory::factory()->create();
        $otra = AssetCategory::factory()->create();
        AssetType::factory()->for($epp, 'category')->create(['name' => 'Pantalón estructural', 'tracking_mode' => TrackingMode::Serialized]);
        AssetType::factory()->for($epp, 'category')->create(['name' => 'Botas estructurales', 'tracking_mode' => TrackingMode::Quantity]);
        AssetType::factory()->for($otra, 'category')->inactive()->create(['name' => 'Camilla', 'tracking_mode' => TrackingMode::Serialized]);

        $this->getJson("/api/asset-types?asset_category_id={$epp->id}")->assertJsonCount(2, 'data');
        $this->getJson('/api/asset-types?tracking_mode=quantity')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Botas estructurales');
        $this->getJson('/api/asset-types?is_active=0')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Camilla');
        $this->getJson('/api/asset-types?search=PANTALON')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Pantalón estructural');

        $this->getJson('/api/asset-types?tracking_mode=otro')->assertJsonValidationErrors('tracking_mode');
    });

    it('US2-4: los tipos de una categoría inactiva siguen visibles con su estado propio', function () {
        actingAsRole('consulta');
        $inactiva = AssetCategory::factory()->inactive()->create(['name' => 'Herramientas']);
        $type = AssetType::factory()->for($inactiva, 'category')->create();

        $this->getJson("/api/asset-types/{$type->id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.category.is_active', false)
            ->assertJsonPath('data.category.name', 'Herramientas');
    });
});

describe('alta', function () {
    it('US2-1: crea un tipo activo asociado a una categoría activa con su modo', function () {
        actingAsRole('admin');
        $epp = AssetCategory::factory()->create(['name' => 'EPP']);

        $this->postJson('/api/asset-types', [
            'asset_category_id' => $epp->id,
            'name' => '  Guantes   de rescate ',
            'tracking_mode' => 'quantity',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Guantes de rescate')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.tracking_mode', 'quantity')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.category.id', $epp->id);
    });

    it('US2-2 / FR-006: el nombre es único por categoría sin distinguir mayúsculas', function () {
        actingAsRole('admin');
        $epp = AssetCategory::factory()->create();
        $otra = AssetCategory::factory()->create();
        AssetType::factory()->for($epp, 'category')->create(['name' => 'Guantes de rescate']);

        $this->postJson('/api/asset-types', ['asset_category_id' => $epp->id, 'name' => 'GUANTES DE RESCATE', 'tracking_mode' => 'quantity'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');

        $this->postJson('/api/asset-types', ['asset_category_id' => $otra->id, 'name' => 'GUANTES DE RESCATE', 'tracking_mode' => 'quantity'])
            ->assertCreated();
    });

    it('US2-3 / FR-007: rechaza una categoría inactiva o inexistente', function () {
        actingAsRole('admin');
        $inactiva = AssetCategory::factory()->inactive()->create();

        $this->postJson('/api/asset-types', ['asset_category_id' => $inactiva->id, 'name' => 'Pala', 'tracking_mode' => 'quantity'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.asset_category_id.0', 'La categoría seleccionada está inactiva.');

        $this->postJson('/api/asset-types', ['asset_category_id' => 999999, 'name' => 'Pala', 'tracking_mode' => 'quantity'])
            ->assertJsonPath('errors.asset_category_id.0', 'El valor seleccionado en categoría no es válido.');

        $this->postJson('/api/asset-types', ['asset_category_id' => 'abc', 'name' => 'Pala', 'tracking_mode' => 'quantity'])
            ->assertJsonValidationErrors('asset_category_id');
    });

    it('US2-6 / RN-007: el modo de control es obligatorio y solo serialized o quantity', function () {
        actingAsRole('admin');
        $epp = AssetCategory::factory()->create();

        $this->postJson('/api/asset-types', ['asset_category_id' => $epp->id, 'name' => 'Pala'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tracking_mode.0', 'El campo modo de control es obligatorio.');
        $this->postJson('/api/asset-types', ['asset_category_id' => $epp->id, 'name' => 'Pala', 'tracking_mode' => 'lote'])
            ->assertJsonValidationErrors('tracking_mode');
        $this->postJson('/api/asset-types', [])
            ->assertJsonValidationErrors(['asset_category_id', 'name', 'tracking_mode']);
    });

    it('B-4: un nombre duplicado en la misma categoría detectado por la base de datos responde 422', function () {
        actingAsRole('admin');
        $epp = AssetCategory::factory()->create();
        AssetType::creating(function (AssetType $type) {
            DB::table('asset_types')->insert([
                'asset_category_id' => $type->asset_category_id,
                'name' => $type->name,
                'tracking_mode' => 'quantity',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->postJson('/api/asset-types', ['asset_category_id' => $epp->id, 'name' => 'Pala', 'tracking_mode' => 'quantity'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
    });
});

describe('edición', function () {
    it('edita nombre, descripción y modo de control mientras no tenga activos (US2-5)', function () {
        actingAsRole('admin');
        $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

        $this->patchJson("/api/asset-types/{$type->id}", ['name' => 'Cilindro SCBA', 'description' => 'De 45 min', 'tracking_mode' => 'quantity'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Cilindro SCBA')
            ->assertJsonPath('data.description', 'De 45 min')
            ->assertJsonPath('data.tracking_mode', 'quantity');
    });

    it('edge case / FR-007: conserva su categoría inactiva, pero no se mueve a otra inactiva', function () {
        actingAsRole('admin');
        $inactiva = AssetCategory::factory()->inactive()->create();
        $otraInactiva = AssetCategory::factory()->inactive()->create();
        $activa = AssetCategory::factory()->create();
        $type = AssetType::factory()->for($inactiva, 'category')->create();

        $this->patchJson("/api/asset-types/{$type->id}", ['asset_category_id' => $inactiva->id, 'name' => 'Renombrado'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renombrado')
            ->assertJsonPath('data.category.id', $inactiva->id);

        $this->patchJson("/api/asset-types/{$type->id}", ['asset_category_id' => $otraInactiva->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.asset_category_id.0', 'La categoría seleccionada está inactiva.');

        $this->patchJson("/api/asset-types/{$type->id}", ['asset_category_id' => $activa->id])
            ->assertOk()
            ->assertJsonPath('data.category.id', $activa->id);
    });

    it('FR-006: al mover de categoría valida la unicidad en la categoría destino', function () {
        actingAsRole('admin');
        $epp = AssetCategory::factory()->create();
        $otra = AssetCategory::factory()->create();
        AssetType::factory()->for($otra, 'category')->create(['name' => 'Casco']);
        $type = AssetType::factory()->for($epp, 'category')->create(['name' => 'casco']);

        $this->patchJson("/api/asset-types/{$type->id}", ['name' => 'CASCO'])->assertOk();
        $this->patchJson("/api/asset-types/{$type->id}", ['asset_category_id' => $otra->id])
            ->assertJsonValidationErrors('name');
    });

    it('FR-009 / D-009: no permite cambiar el modo de control si el tipo ya tiene activos', function () {
        actingAsRole('admin');
        $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

        // Doble de prueba: mismo registro, pero con activos registrados.
        $withAssets = new class extends AssetType
        {
            protected $table = 'asset_types';

            public function hasAssets(): bool
            {
                return true;
            }
        };
        $bound = $withAssets->newFromBuilder($type->getAttributes());
        Gate::policy($withAssets::class, AssetTypePolicy::class);
        Route::bind('asset_type', fn () => $bound);

        $this->patchJson("/api/asset-types/{$type->id}", ['tracking_mode' => 'quantity'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.tracking_mode.0', 'No se puede cambiar el modo de control: el tipo ya tiene activos registrados.');

        $this->patchJson("/api/asset-types/{$type->id}", ['tracking_mode' => 'serialized', 'name' => 'Otro nombre'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Otro nombre');

        expect($type->fresh()->tracking_mode)->toBe(TrackingMode::Serialized);
    });
});

describe('estado y autorización', function () {
    it('RN-003: desactiva y reactiva; no existe DELETE (405)', function () {
        actingAsRole('admin');
        $type = AssetType::factory()->create();

        $this->patchJson("/api/asset-types/{$type->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->patchJson("/api/asset-types/{$type->id}/status", ['is_active' => true])
            ->assertJsonPath('data.is_active', true);

        $this->deleteJson("/api/asset-types/{$type->id}")->assertMethodNotAllowed();
    });

    it('RN-011: 401 sin sesión, 403 sin ver y 403 al modificar sin gestionar', function () {
        $type = AssetType::factory()->create();

        $this->getJson('/api/asset-types')->assertUnauthorized();

        actingWithPermissions([]);
        $this->getJson('/api/asset-types')->assertForbidden();
        $this->getJson("/api/asset-types/{$type->id}")->assertForbidden();

        actingAsRole('almacenista');
        $this->getJson('/api/asset-types')->assertOk();
        $this->postJson('/api/asset-types', [])->assertForbidden();
        $this->patchJson("/api/asset-types/{$type->id}", ['name' => 'X'])->assertForbidden();
        $this->patchJson("/api/asset-types/{$type->id}/status", ['is_active' => false])->assertForbidden();
    });
});
