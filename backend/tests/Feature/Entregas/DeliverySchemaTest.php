<?php

use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T001 — Bloque 007 de esquema-bd (RN-023..RN-026, RN-038): entregas
 * inmutables con consecutivo, ítems con devuelto acumulado y devoluciones de
 * solo inserción.
 */
function rawDelivery(array $attributes = []): int
{
    return DB::table('deliveries')->insertGetId([
        'employee_id' => Employee::factory()->create()->id,
        'kind' => 'dotacion',
        'from_location_id' => Location::factory()->create()->id,
        'signature_path' => 'signatures/x.png',
        'signature_sha256' => str_repeat('a', 64),
        'delivered_by' => User::factory()->create()->id,
        'delivered_at' => now(),
        ...$attributes,
    ]);
}

function rawDeliveryItem(int $deliveryId, array $attributes = []): int
{
    return DB::table('delivery_items')->insertGetId([
        'delivery_id' => $deliveryId,
        'asset_type_id' => $attributes['asset_type_id'] ?? AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity])->id,
        'quantity' => 2,
        ...$attributes,
    ]);
}

function rawDeliveryReturn(int $itemId, array $attributes = []): int
{
    return DB::table('delivery_returns')->insertGetId([
        'delivery_item_id' => $itemId,
        'quantity' => 1,
        'condition' => 'good',
        'to_location_id' => Location::factory()->create()->id,
        'received_by' => User::factory()->create()->id,
        'returned_at' => now(),
        ...$attributes,
    ]);
}

it('T001: crea las tablas del bloque 007 con sus columnas', function () {
    expect(Schema::hasColumns('deliveries', [
        'id', 'code', 'employee_id', 'kind', 'due_on', 'from_location_id', 'signature_path', 'signature_sha256', 'notes',
        'delivered_by', 'delivered_at', 'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('deliveries', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('delivery_items', ['id', 'delivery_id', 'asset_id', 'asset_type_id', 'quantity', 'returned_quantity']))->toBeTrue()
        ->and(Schema::hasColumns('delivery_returns', [
            'id', 'delivery_item_id', 'quantity', 'condition', 'to_location_id', 'notes', 'received_by', 'returned_at', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('delivery_returns', 'updated_at'))->toBeFalse();
});

it('D-001: el consecutivo ENT-000001 lo asigna la secuencia deliveries_code_seq al insertar', function () {
    $first = rawDelivery();
    $second = rawDelivery();

    $codes = DB::table('deliveries')->whereIn('id', [$first, $second])->orderBy('id')->pluck('code')->all();

    expect($codes[0])->toMatch('/^ENT-\d{6}$/')
        ->and((int) substr($codes[1], 4))->toBe((int) substr($codes[0], 4) + 1)
        ->and(fn () => inSavepoint(fn () => rawDelivery(['code' => $codes[0]])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('RN-023: tipo dotación o préstamo; el préstamo exige fecha esperada y la dotación no la lleva', function () {
    expect(fn () => inSavepoint(fn () => rawDelivery(['kind' => 'regalo'])))
        ->toThrow(QueryException::class, 'deliveries_kind_check')
        ->and(fn () => inSavepoint(fn () => rawDelivery(['kind' => 'prestamo'])))
        ->toThrow(QueryException::class, 'deliveries_due_on_check')
        ->and(fn () => inSavepoint(fn () => rawDelivery(['kind' => 'dotacion', 'due_on' => '2026-11-01'])))
        ->toThrow(QueryException::class, 'deliveries_due_on_check');

    expect(rawDelivery(['kind' => 'prestamo', 'due_on' => '2026-11-01']))->toBeInt();
});

it('FR-001 / US1-6: las entregas y las devoluciones son de solo inserción', function () {
    $deliveryId = rawDelivery();
    $itemId = rawDeliveryItem($deliveryId);
    $returnId = rawDeliveryReturn($itemId);

    foreach (['deliveries' => $deliveryId, 'delivery_returns' => $returnId] as $table => $id) {
        expect(fn () => inSavepoint(fn () => DB::table($table)->where('id', $id)->update(['notes' => 'x'])))
            ->toThrow(QueryException::class, 'solo inserción')
            ->and(fn () => inSavepoint(fn () => DB::table($table)->where('id', $id)->delete()))
            ->toThrow(QueryException::class, 'solo inserción')
            ->and(fn () => inSavepoint(fn () => DB::statement("TRUNCATE {$table} CASCADE")))
            ->toThrow(QueryException::class, 'solo inserción');
    }

    // D-004: lo devuelto se acumula en el ítem (no es de solo inserción).
    DB::table('delivery_items')->where('id', $itemId)->update(['returned_quantity' => 1]);

    expect(DB::table('delivery_items')->where('id', $itemId)->value('returned_quantity'))->toBe(1);
});

it('RN-025: un ítem tiene cantidad positiva, devuelto entre 0 y la cantidad y un serializado es de 1 unidad', function () {
    $deliveryId = rawDelivery();
    $serialized = Asset::factory()->create();

    expect(fn () => inSavepoint(fn () => rawDeliveryItem($deliveryId, ['quantity' => 0])))
        ->toThrow(QueryException::class, 'delivery_items_quantity_check')
        ->and(fn () => inSavepoint(fn () => rawDeliveryItem($deliveryId, ['quantity' => 2, 'returned_quantity' => 3])))
        ->toThrow(QueryException::class, 'delivery_items_returned_quantity_check')
        ->and(fn () => inSavepoint(fn () => rawDeliveryItem($deliveryId, ['asset_id' => $serialized->id, 'asset_type_id' => $serialized->asset_type_id, 'quantity' => 2])))
        ->toThrow(QueryException::class, 'delivery_items_asset_quantity_check');
});

it('edge case: un activo no puede estar pendiente en dos entregas a la vez', function () {
    $asset = Asset::factory()->create();
    $item = ['asset_id' => $asset->id, 'asset_type_id' => $asset->asset_type_id, 'quantity' => 1];

    $firstItem = rawDeliveryItem(rawDelivery(), $item);

    expect(fn () => inSavepoint(fn () => rawDeliveryItem(rawDelivery(), $item)))
        ->toThrow(UniqueConstraintViolationException::class, 'delivery_items_pending_asset_unique');

    DB::table('delivery_items')->where('id', $firstItem)->update(['returned_quantity' => 1]);

    expect(rawDeliveryItem(rawDelivery(), $item))->toBeInt();
});

it('RN-026 / RN-038: estado de devolución válido; lo perdido no vuelve a una ubicación y exige observación', function () {
    $itemId = rawDeliveryItem(rawDelivery());

    expect(fn () => inSavepoint(fn () => rawDeliveryReturn($itemId, ['condition' => 'roto'])))
        ->toThrow(QueryException::class, 'violates check constraint')
        ->and(fn () => inSavepoint(fn () => rawDeliveryReturn($itemId, ['quantity' => 0])))
        ->toThrow(QueryException::class, 'delivery_returns_quantity_check')
        ->and(fn () => inSavepoint(fn () => rawDeliveryReturn($itemId, ['condition' => 'good', 'to_location_id' => null])))
        ->toThrow(QueryException::class, 'delivery_returns_location_check')
        ->and(fn () => inSavepoint(fn () => rawDeliveryReturn($itemId, ['condition' => 'lost', 'notes' => 'Se perdió'])))
        ->toThrow(QueryException::class, 'delivery_returns_location_check')
        ->and(fn () => inSavepoint(fn () => rawDeliveryReturn($itemId, ['condition' => 'lost', 'to_location_id' => null, 'notes' => ' '])))
        ->toThrow(QueryException::class, 'delivery_returns_lost_notes_check');

    expect(rawDeliveryReturn($itemId, ['condition' => 'lost', 'to_location_id' => null, 'notes' => 'Se perdió en incendio']))->toBeInt()
        ->and(rawDeliveryReturn($itemId, ['condition' => 'damaged', 'to_location_id' => null]))->toBeInt();
});

it('T001: delivery_id de movimientos de inventario e historial de activos apunta a deliveries', function () {
    $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);
    $user = User::factory()->create();
    $movement = ['type' => 'loss', 'asset_type_id' => $type->id, 'quantity' => 1, 'user_id' => $user->id];
    $event = ['asset_id' => Asset::factory()->create()->id, 'event' => 'delivered', 'user_id' => $user->id];

    expect(fn () => inSavepoint(fn () => DB::table('inventory_movements')->insert([...$movement, 'delivery_id' => 999_999])))
        ->toThrow(QueryException::class, 'inventory_movements_delivery_id_foreign')
        ->and(fn () => inSavepoint(fn () => DB::table('asset_events')->insert([...$event, 'delivery_id' => 999_999])))
        ->toThrow(QueryException::class, 'asset_events_delivery_id_foreign');

    $deliveryId = rawDelivery();
    DB::table('inventory_movements')->insert([...$movement, 'delivery_id' => $deliveryId]);
    DB::table('asset_events')->insert([...$event, 'delivery_id' => $deliveryId]);

    expect(DB::table('inventory_movements')->where('delivery_id', $deliveryId)->count())->toBe(1);
});

it('M-3: signature_sha256 es obligatoria y es un SHA-256 en hexadecimal (minúsculas)', function () {
    expect(fn () => inSavepoint(fn () => rawDelivery(['signature_sha256' => null])))
        ->toThrow(QueryException::class, 'signature_sha256')
        ->and(fn () => inSavepoint(fn () => rawDelivery(['signature_sha256' => str_repeat('z', 64)])))
        ->toThrow(QueryException::class, 'deliveries_signature_sha256_check')
        ->and(fn () => inSavepoint(fn () => rawDelivery(['signature_sha256' => strtoupper(hash('sha256', 'x'))])))
        ->toThrow(QueryException::class, 'deliveries_signature_sha256_check');

    expect(rawDelivery(['signature_sha256' => hash('sha256', 'firma')]))->toBeInt();
});

describe('M-2: guarda de delivery_items (solo se permite aumentar returned_quantity)', function () {
    beforeEach(function () {
        $this->itemId = rawDeliveryItem(rawDelivery(), ['quantity' => 3, 'returned_quantity' => 1]);
    });

    it('D-004: permite aumentar returned_quantity (y repetir el mismo valor)', function () {
        DB::table('delivery_items')->where('id', $this->itemId)->increment('returned_quantity', 2);
        DB::table('delivery_items')->where('id', $this->itemId)->update(['returned_quantity' => 3]);

        expect(DB::table('delivery_items')->where('id', $this->itemId)->value('returned_quantity'))->toBe(3);
    });

    it('rechaza disminuir returned_quantity', function () {
        expect(fn () => inSavepoint(fn () => DB::table('delivery_items')->where('id', $this->itemId)->update(['returned_quantity' => 0])))
            ->toThrow(QueryException::class, 'delivery_items');
    });

    it('rechaza cambiar cualquier otra columna', function (string $column, Closure $value) {
        $newValue = $value();

        expect(fn () => inSavepoint(fn () => DB::table('delivery_items')->where('id', $this->itemId)->update([$column => $newValue])))
            ->toThrow(QueryException::class, 'delivery_items');
    })->with([
        'id' => ['id', fn () => 999_999],
        'delivery_id' => ['delivery_id', fn () => rawDelivery()],
        'asset_type_id' => ['asset_type_id', fn () => AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity])->id],
        'quantity' => ['quantity', fn () => 5],
    ]);

    it('rechaza cambiar asset_id', function () {
        $asset = Asset::factory()->create();
        $itemId = rawDeliveryItem(rawDelivery(), ['quantity' => 1, 'asset_type_id' => $asset->asset_type_id]);

        expect(fn () => inSavepoint(fn () => DB::table('delivery_items')->where('id', $itemId)->update(['asset_id' => $asset->id])))
            ->toThrow(QueryException::class, 'delivery_items');
    });

    it('rechaza aumentar returned_quantity si a la vez cambia otra columna', function () {
        expect(fn () => inSavepoint(fn () => DB::table('delivery_items')->where('id', $this->itemId)->update(['returned_quantity' => 2, 'quantity' => 4])))
            ->toThrow(QueryException::class, 'delivery_items');
    });

    it('rechaza DELETE y TRUNCATE', function () {
        expect(fn () => inSavepoint(fn () => DB::table('delivery_items')->where('id', $this->itemId)->delete()))
            ->toThrow(QueryException::class, 'delivery_items')
            ->and(fn () => inSavepoint(fn () => DB::statement('TRUNCATE delivery_items CASCADE')))
            ->toThrow(QueryException::class, 'solo inserción');

        expect(DB::table('delivery_items')->where('id', $this->itemId)->exists())->toBeTrue();
    });
});
