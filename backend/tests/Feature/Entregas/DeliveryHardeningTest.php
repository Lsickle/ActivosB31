<?php

use App\Actions\Activos\ReleaseAsset;
use App\Actions\Entregas\CreateDelivery;
use App\Actions\Entregas\RegisterDeliveryReturn;
use App\Actions\Inventario\RecordStockMovement;
use App\Enums\AssetStatus;
use App\Enums\DeliveryKind;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryReturn;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockLevel;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * Correcciones de la revisión de seguridad de 007 (M-1, M-3, B-1, B-2, B-5).
 * Los de esquema (M-2, M-3) están en DeliverySchemaTest y los de la firma
 * (B-3) en DeliverySignatureTest.
 */
beforeEach(function () {
    Storage::fake('local');
});

describe('M-3: huella SHA-256 de la firma', function () {
    beforeEach(function () {
        $this->scenario = deliveryScenario();
        $this->user = actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);
    });

    it('RN-024 / D-002: guarda el SHA-256 del PNG re-codificado y signatureIntact() lo verifica', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertCreated();

        $delivery = Delivery::query()->sole();
        $stored = Storage::disk('local')->get($delivery->signature_path);

        expect($delivery->signature_sha256)->toBe(hash('sha256', $stored))
            ->and($delivery->signatureIntact())->toBeTrue();
    });

    it('signatureIntact() es falso si el archivo cambió o no existe', function () {
        $delivery = app(CreateDelivery::class)->handle(
            $this->scenario['employee'], DeliveryKind::Dotacion, null, $this->scenario['warehouse'], null, signaturePng(),
            [['asset_id' => $this->scenario['helmet']->id]], $this->user,
        );

        Storage::disk('local')->put($delivery->signature_path, signaturePng(300, 120));
        expect($delivery->signatureIntact())->toBeFalse();

        Storage::disk('local')->delete($delivery->signature_path);
        expect($delivery->signatureIntact())->toBeFalse();
    });
});

it('B-1: id, signature_path, signature_sha256 y returned_quantity no son asignables en masa', function () {
    expect((new Delivery)->getFillable())->not->toContain('id')
        ->not->toContain('signature_path')
        ->not->toContain('signature_sha256')
        ->and((new DeliveryItem)->getFillable())->not->toContain('returned_quantity')
        ->and((new Delivery)->isFillable('notes'))->toBeTrue()
        ->and((new DeliveryItem)->isFillable('quantity'))->toBeTrue();
});

describe('B-5: ubicación desactivada en carrera durante la devolución', function () {
    beforeEach(function () {
        $this->scenario = deliveryScenario();
        $this->engine = Location::factory()->create(['name' => 'Máquina 1']);
        $this->user = actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);
        $this->delivery = app(CreateDelivery::class)->handle(
            $this->scenario['employee'], DeliveryKind::Dotacion, null, $this->scenario['warehouse'], null, signaturePng(),
            [['asset_id' => $this->scenario['helmet']->id], ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 2]],
            $this->user,
        );
    });

    it('serializado: el InvalidArgumentException de ReleaseAsset se informa en items.N.to_location_id (422) y no registra nada', function () {
        // La validación previa ve la ubicación disponible; ReleaseAsset la ve
        // ya desactivada (otra petición la desactivó entre ambas lecturas).
        $this->mock(ReleaseAsset::class)
            ->shouldReceive('handle')
            ->andThrow(new InvalidArgumentException('La ubicación seleccionada está inactiva.'));

        $helmetItem = $this->delivery->items()->whereNotNull('asset_id')->sole();
        $glovesItem = $this->delivery->items()->whereNull('asset_id')->sole();

        $this->postJson("/api/deliveries/{$this->delivery->id}/returns", ['items' => [
            ['delivery_item_id' => $glovesItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
            ['delivery_item_id' => $helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.to_location_id' => RegisterDeliveryReturn::INACTIVE_LOCATION_MESSAGE]);

        expect(DeliveryReturn::query()->count())->toBe(0)
            ->and($helmetItem->fresh()->returned_quantity)->toBe(0)
            ->and($this->scenario['helmet']->fresh()->status)->toBe(AssetStatus::Assigned);
    });

    it('por cantidad: si la ubicación se desactiva antes de mover la existencia, 422 en items.N.to_location_id y no registra nada', function () {
        $glovesItem = $this->delivery->items()->whereNull('asset_id')->sole();
        $stockBefore = StockLevel::quantityOf($this->scenario['gloves'], $this->engine);
        $movementsBefore = InventoryMovement::query()->count();

        // Carrera: otra petición desactiva la ubicación después de la
        // validación previa de RegisterDeliveryReturn (se simula al crear la
        // devolución, justo antes de mover la existencia).
        DeliveryReturn::creating(function () {
            Location::query()->whereKey($this->engine->id)->update(['is_active' => false]);
        });

        $this->postJson("/api/deliveries/{$this->delivery->id}/returns", ['items' => [
            ['delivery_item_id' => $glovesItem->id, 'quantity' => 2, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.to_location_id' => RegisterDeliveryReturn::INACTIVE_LOCATION_MESSAGE])
            ->assertJsonMissingValidationErrors(['to_location_id']);

        expect(DeliveryReturn::query()->count())->toBe(0)
            ->and($glovesItem->fresh()->returned_quantity)->toBe(0)
            ->and(InventoryMovement::query()->count())->toBe($movementsBefore)
            ->and(StockLevel::quantityOf($this->scenario['gloves'], $this->engine))->toBe($stockBefore);
    });
});

describe('B-2: permisos cruzados del asistente de entrega (solo entregas.gestionar)', function () {
    it('GET /api/assets devuelve un recurso reducido', function () {
        actingWithPermissions([Permission::EntregasGestionar->value]);
        $location = Location::factory()->create(['name' => 'Bodega']);
        $asset = Asset::factory()->at($location)->create(['plate' => 'CAS-100', 'serial' => 'S-1', 'brand' => 'MSA', 'notes' => 'Nota interna']);

        $row = $this->getJson('/api/assets')->assertOk()->json('data.0');

        expect($row)->toBe([
            'id' => $asset->id,
            'plate' => 'CAS-100',
            'serial' => 'S-1',
            'status' => 'available',
            'asset_type' => ['id' => $asset->asset_type_id, 'name' => $asset->assetType->name],
            'location' => ['id' => $location->id, 'name' => 'Bodega'],
        ]);
    });

    it('con activos.ver sigue recibiendo el recurso completo', function () {
        actingWithPermissions([Permission::ActivosVer->value, Permission::EntregasGestionar->value]);
        Asset::factory()->create(['brand' => 'MSA']);

        expect($this->getJson('/api/assets')->assertOk()->json('data.0'))
            ->toHaveKeys(['brand', 'model', 'acquisition_value', 'notes', 'holder', 'next_maintenance_on']);
    });

    it('GET /api/inventory/stock ignora below_min sin inventario.ver', function () {
        $location = Location::factory()->create();
        $type = AssetType::factory()->create(['tracking_mode' => 'quantity', 'min_stock' => 100]);
        $other = AssetType::factory()->create(['tracking_mode' => 'quantity', 'min_stock' => null]);
        $user = actingWithPermissions([Permission::EntregasGestionar->value]);
        app(RecordStockMovement::class)->handle(MovementType::Entry, $type, 5, null, $location, null, $user);
        app(RecordStockMovement::class)->handle(MovementType::Entry, $other, 5, null, $location, null, $user);

        $this->getJson('/api/inventory/stock?below_min=1')->assertOk()->assertJsonPath('meta.total', 2);

        actingWithPermissions([Permission::InventarioVer->value]);

        $this->getJson('/api/inventory/stock?below_min=1')->assertOk()->assertJsonPath('meta.total', 1);
    });
});

describe('M-1 (provisional 33A): URL de la firma', function () {
    beforeEach(function () {
        $this->delivery = Delivery::factory()->create();
        DeliveryItem::factory()->for($this->delivery)->create();
    });

    it('con solo entregas.ver la ficha no trae signature_url', function () {
        actingWithPermissions([Permission::EntregasVer->value]);

        $this->getJson("/api/deliveries/{$this->delivery->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.signature_url');
    });

    it('con entregas.gestionar o empleados.ver la ficha trae signature_url', function (array $permissions) {
        actingWithPermissions([Permission::EntregasVer->value, ...$permissions]);

        $this->getJson("/api/deliveries/{$this->delivery->id}")
            ->assertOk()
            ->assertJsonPath('data.signature_url', fn (string $url) => str_contains($url, $this->delivery->signature_path));
    })->with([
        'entregas.gestionar' => [[Permission::EntregasGestionar->value]],
        'empleados.ver' => [[Permission::EmpleadosVer->value]],
    ]);
});
