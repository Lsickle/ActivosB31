<?php

use App\Models\AssetCategory;
use App\Models\AssetType;
use Illuminate\Support\Facades\DB;

describe('listado', function () {
    it('FR-013: pagina y ordena por nombre, con el conteo de tipos', function () {
        actingAsRole('consulta');
        $epp = AssetCategory::factory()->create(['name' => 'EPP', 'description' => 'Protección']);
        AssetType::factory()->count(2)->for($epp, 'category')->create();
        AssetCategory::factory()->create(['name' => 'Comunicaciones']);
        AssetCategory::factory()->inactive()->create(['name' => 'Vehículos']);

        $this->getJson('/api/asset-categories?per_page=2')
            ->assertOk()
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('data.0.name', 'Comunicaciones')
            ->assertJsonPath('data.1.name', 'EPP')
            ->assertJsonPath('data.1.description', 'Protección')
            ->assertJsonPath('data.1.is_active', true)
            ->assertJsonPath('data.1.asset_types_count', 2)
            ->assertJsonStructure(['data' => [['id', 'name', 'description', 'is_active', 'asset_types_count', 'created_at', 'updated_at']], 'meta', 'links']);
    });

    it('D-004: usa 25 por página por defecto y rechaza más de 100', function () {
        actingAsRole('consulta');

        $this->getJson('/api/asset-categories')->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->getJson('/api/asset-categories?per_page=101')->assertJsonValidationErrors('per_page');
    });

    it('US1-4 / D-003: busca sin distinguir mayúsculas ni tildes', function () {
        actingAsRole('consulta');
        AssetCategory::factory()->create(['name' => 'Equipos de extinción']);
        AssetCategory::factory()->create(['name' => 'Equipos de respiración autónoma']);
        AssetCategory::factory()->create(['name' => 'Comunicaciones']);

        $this->getJson('/api/asset-categories?search=EXTINCION')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Equipos de extinción');

        $this->getJson('/api/asset-categories?search=equipos')->assertJsonCount(2, 'data');
    });

    it('SC-003: filtra por estado y los inactivos siguen consultables', function () {
        actingAsRole('consulta');
        AssetCategory::factory()->create(['name' => 'Activa']);
        AssetCategory::factory()->inactive()->create(['name' => 'Inactiva']);

        $this->getJson('/api/asset-categories?is_active=0')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Inactiva')
            ->assertJsonPath('data.0.is_active', false);
        $this->getJson('/api/asset-categories?is_active=true')
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Activa');
    });

    it('muestra una categoría con su conteo de tipos', function () {
        actingAsRole('consulta');
        $category = AssetCategory::factory()->has(AssetType::factory()->count(3), 'assetTypes')->create();

        $this->getJson("/api/asset-categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.asset_types_count', 3);

        $this->getJson('/api/asset-categories/999999')->assertNotFound();
    });
});

describe('alta y edición', function () {
    it('US1-1: crea una categoría activa con descripción opcional', function () {
        actingAsRole('admin');

        $this->postJson('/api/asset-categories', ['name' => 'EPP', 'description' => 'Protección personal'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'EPP')
            ->assertJsonPath('data.description', 'Protección personal')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.asset_types_count', 0);

        $this->postJson('/api/asset-categories', ['name' => 'Consumibles'])
            ->assertCreated()
            ->assertJsonPath('data.description', null);
    });

    it('no permite fijar el estado al crear', function () {
        actingAsRole('admin');

        $this->postJson('/api/asset-categories', ['name' => 'EPP', 'is_active' => false])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true);
    });

    it('D-002: recorta y colapsa espacios del nombre; la descripción vacía queda null', function () {
        actingAsRole('admin');

        $this->postJson('/api/asset-categories', ['name' => '  Equipos   de   rescate ', 'description' => '   '])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Equipos de rescate')
            ->assertJsonPath('data.description', null);

        $this->postJson('/api/asset-categories', ['name' => 'equipos de  RESCATE'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
    });

    it('US1-2 / FR-006: rechaza nombres duplicados sin distinguir mayúsculas, incluidos los inactivos', function () {
        actingAsRole('admin');
        AssetCategory::factory()->create(['name' => 'EPP']);
        AssetCategory::factory()->inactive()->create(['name' => 'Vehículos']);

        $this->postJson('/api/asset-categories', ['name' => 'epp'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
        $this->postJson('/api/asset-categories', ['name' => 'VEHÍCULOS'])->assertJsonValidationErrors('name');
    });

    it('valida nombre obligatorio y longitudes (120 / 500)', function () {
        actingAsRole('admin');

        $this->postJson('/api/asset-categories', ['name' => '   '])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El campo nombre es obligatorio.');
        $this->postJson('/api/asset-categories', ['name' => str_repeat('a', 121)])->assertJsonValidationErrors('name');
        $this->postJson('/api/asset-categories', ['name' => str_repeat('a', 120), 'description' => str_repeat('b', 501)])
            ->assertJsonValidationErrors('description')
            ->assertJsonMissingValidationErrors('name');
    });

    it('edita nombre y descripción; puede conservar su propio nombre', function () {
        actingAsRole('admin');
        $category = AssetCategory::factory()->create(['name' => 'EPP']);
        AssetCategory::factory()->create(['name' => 'Vehículos']);

        $this->patchJson("/api/asset-categories/{$category->id}", ['name' => 'epp', 'description' => ''])
            ->assertOk()
            ->assertJsonPath('data.name', 'epp')
            ->assertJsonPath('data.description', null);

        $this->patchJson("/api/asset-categories/{$category->id}", ['name' => 'vehículos'])->assertJsonValidationErrors('name');
    });

    it('B-4: un nombre duplicado detectado por la base de datos responde 422', function () {
        actingAsRole('admin');
        AssetCategory::creating(function (AssetCategory $category) {
            DB::table('asset_categories')->insert(['name' => $category->name, 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->postJson('/api/asset-categories', ['name' => 'EPP'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.name.0', 'El valor del campo nombre ya está en uso.');
    });
});

describe('estado', function () {
    it('US1-3 / FR-008: desactiva y reactiva sin modificar sus tipos', function () {
        actingAsRole('admin');
        $category = AssetCategory::factory()->create();
        $type = AssetType::factory()->for($category, 'category')->create();

        $this->patchJson("/api/asset-categories/{$category->id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        expect($type->fresh()->is_active)->toBeTrue();

        $this->patchJson("/api/asset-categories/{$category->id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->patchJson("/api/asset-categories/{$category->id}/status", [])->assertJsonValidationErrors('is_active');
    });

    it('US1-5 / RN-003: no existe la eliminación (405)', function () {
        actingAsRole('admin');
        $category = AssetCategory::factory()->create();

        $this->deleteJson("/api/asset-categories/{$category->id}")->assertMethodNotAllowed();

        expect($category->fresh())->not->toBeNull();
    });
});

describe('autorización (RN-011, FR-011)', function () {
    it('responde 401 sin sesión', function () {
        $this->getJson('/api/asset-categories')->assertUnauthorized();
        $this->postJson('/api/asset-categories', ['name' => 'EPP'])->assertUnauthorized();
    });

    it('responde 403 sin configuracion.ver', function () {
        actingWithPermissions([]);
        $category = AssetCategory::factory()->create();

        $this->getJson('/api/asset-categories')->assertForbidden();
        $this->getJson("/api/asset-categories/{$category->id}")->assertForbidden();
    });

    it('responde 403 al modificar sin configuracion.gestionar, antes de validar', function () {
        actingAsRole('consulta');
        $category = AssetCategory::factory()->create();

        $this->postJson('/api/asset-categories', [])->assertForbidden();
        $this->patchJson("/api/asset-categories/{$category->id}", ['name' => 'Otra'])->assertForbidden();
        $this->patchJson("/api/asset-categories/{$category->id}/status", ['is_active' => false])->assertForbidden();

        expect($category->fresh()->is_active)->toBeTrue();
    });

    it('exige haber cambiado la contraseña temporal (RN-014)', function () {
        actingAsRole('admin', ['must_change_password' => true]);

        $this->getJson('/api/asset-categories')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_change_required');
    });
});
