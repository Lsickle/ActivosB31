<?php

use App\Enums\TrackingMode;
use App\Models\AssetType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * T001 — Bloque 006 de esquema-bd (RN-027, RN-028): existencias, movimientos
 * de solo inserción y conteos físicos con un conteo abierto por ubicación.
 */
function rawMovement(array $attributes = []): int
{
    return DB::table('inventory_movements')->insertGetId([
        'type' => 'entry',
        'asset_type_id' => $attributes['asset_type_id'] ?? AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity])->id,
        'quantity' => 1,
        'to_location_id' => $attributes['to_location_id'] ?? Location::factory()->create()->id,
        'user_id' => User::factory()->create()->id,
        ...$attributes,
    ]);
}

function rawCount(int $locationId, string $status = 'open'): int
{
    $userId = User::factory()->create()->id;

    return DB::table('inventory_counts')->insertGetId([
        'location_id' => $locationId,
        'status' => $status,
        'opened_by' => $userId,
        'opened_at' => now(),
        ...($status === 'closed' ? ['closed_by' => $userId, 'closed_at' => now()] : []),
    ]);
}

it('T001: crea las tablas del bloque 006 con sus columnas', function () {
    expect(Schema::hasColumns('stock_levels', ['asset_type_id', 'location_id', 'quantity', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumn('stock_levels', 'id'))->toBeFalse()
        ->and(Schema::hasColumns('inventory_movements', [
            'id', 'type', 'asset_type_id', 'quantity', 'from_location_id', 'to_location_id',
            'delivery_id', 'count_id', 'reason', 'user_id', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumn('inventory_movements', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('inventory_counts', [
            'id', 'location_id', 'status', 'notes', 'opened_by', 'closed_by', 'opened_at', 'closed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('inventory_count_lines', [
            'id', 'count_id', 'asset_type_id', 'asset_id', 'expected_quantity', 'counted_quantity', 'found',
        ]))->toBeTrue();
});

it('US3-4: las fechas de movimientos y conteos guardan microsegundos', function () {
    $precision = fn (string $table, string $column) => DB::scalar(
        'select datetime_precision from information_schema.columns where table_name = ? and column_name = ?',
        [$table, $column],
    );

    expect($precision('inventory_movements', 'created_at'))->toBe(6)
        ->and($precision('inventory_counts', 'opened_at'))->toBe(6)
        ->and($precision('inventory_counts', 'closed_at'))->toBe(6);
});

it('FR-002: la existencia nunca es negativa y hay una fila por tipo y ubicación', function () {
    $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);
    $location = Location::factory()->create();
    $row = ['asset_type_id' => $type->id, 'location_id' => $location->id, 'updated_at' => now()];

    expect(fn () => inSavepoint(fn () => DB::table('stock_levels')->insert([...$row, 'quantity' => -1])))
        ->toThrow(QueryException::class, 'stock_levels_quantity_check');

    DB::table('stock_levels')->insert([...$row, 'quantity' => 0]);

    expect(fn () => inSavepoint(fn () => DB::table('stock_levels')->insert([...$row, 'quantity' => 3])))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('D-002: los movimientos tienen clase válida, cantidad positiva y ubicaciones coherentes', function () {
    $location = Location::factory()->create();

    // Una clase desconocida también incumple el CHECK de ubicaciones: basta con que falle.
    expect(fn () => inSavepoint(fn () => rawMovement(['type' => 'gift'])))
        ->toThrow(QueryException::class, 'violates check constraint')
        ->and(fn () => inSavepoint(fn () => rawMovement(['quantity' => 0])))
        ->toThrow(QueryException::class, 'inventory_movements_quantity_check')
        ->and(fn () => inSavepoint(fn () => rawMovement(['type' => 'transfer', 'from_location_id' => $location->id, 'to_location_id' => $location->id])))
        ->toThrow(QueryException::class, 'inventory_movements_locations_check')
        ->and(fn () => inSavepoint(fn () => rawMovement(['type' => 'exit', 'to_location_id' => $location->id])))
        ->toThrow(QueryException::class, 'inventory_movements_locations_check')
        ->and(fn () => inSavepoint(fn () => rawMovement(['type' => 'loss', 'to_location_id' => $location->id])))
        ->toThrow(QueryException::class, 'inventory_movements_locations_check');

    expect(rawMovement(['type' => 'loss', 'to_location_id' => null]))->toBeInt();
});

it('FR-001: los movimientos son de solo inserción (trigger prevent_row_changes)', function () {
    $id = rawMovement();

    expect(fn () => inSavepoint(fn () => DB::table('inventory_movements')->where('id', $id)->update(['quantity' => 5])))
        ->toThrow(QueryException::class, 'solo inserción')
        ->and(fn () => inSavepoint(fn () => DB::table('inventory_movements')->where('id', $id)->delete()))
        ->toThrow(QueryException::class, 'solo inserción')
        ->and(fn () => inSavepoint(fn () => DB::statement('TRUNCATE inventory_movements CASCADE')))
        ->toThrow(QueryException::class, 'solo inserción');

    expect(DB::table('inventory_movements')->where('id', $id)->value('quantity'))->toBe(1);
});

it('D-004: solo puede haber un conteo abierto por ubicación', function () {
    $location = Location::factory()->create();

    rawCount($location->id, 'closed');
    rawCount($location->id, 'closed');
    rawCount($location->id);

    expect(fn () => inSavepoint(fn () => rawCount($location->id)))
        ->toThrow(UniqueConstraintViolationException::class, 'inventory_counts_one_open_per_location')
        ->and(fn () => inSavepoint(fn () => rawCount($location->id, 'paused')))
        ->toThrow(QueryException::class, 'inventory_counts_status_check');
});

it('D-004: un renglón es de cantidad o de activo serializado', function () {
    $location = Location::factory()->create();
    $countId = rawCount($location->id);
    $type = AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity]);
    $line = ['count_id' => $countId, 'asset_type_id' => $type->id];

    DB::table('inventory_count_lines')->insert([...$line, 'expected_quantity' => 5, 'counted_quantity' => 4]);

    expect(fn () => inSavepoint(fn () => DB::table('inventory_count_lines')->insert([...$line, 'expected_quantity' => 1])))
        ->toThrow(UniqueConstraintViolationException::class)
        ->and(fn () => inSavepoint(fn () => DB::table('inventory_count_lines')->insert([
            'count_id' => $countId, 'asset_type_id' => AssetType::factory()->create()->id, 'expected_quantity' => 1, 'counted_quantity' => -1,
        ])))->toThrow(QueryException::class, 'inventory_count_lines_quantities_check')
        ->and(fn () => inSavepoint(fn () => DB::table('inventory_count_lines')->insert([
            'count_id' => $countId, 'asset_type_id' => AssetType::factory()->create()->id, 'expected_quantity' => 1, 'found' => true,
        ])))->toThrow(QueryException::class, 'inventory_count_lines_kind_check');
});
