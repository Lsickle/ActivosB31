<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Models\Delivery;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\LocationType;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * T003 — RecordStockMovement (D-001, D-002, D-003): única vía para cambiar
 * stock_levels. FR-001, FR-002, SC-001, SC-002, RN-007, RN-038.
 */
function quantityType(array $attributes = []): AssetType
{
    return AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, ...$attributes]);
}

function record(MovementType $type, AssetType $assetType, int $quantity, ?Location $from = null, ?Location $to = null, ?string $reason = null, ?User $user = null, ?int $deliveryId = null): InventoryMovement
{
    return app(RecordStockMovement::class)->handle($type, $assetType, $quantity, $from, $to, $reason, $user ?? User::factory()->create(), $deliveryId);
}

/**
 * @return array<string, list<string>>
 */
function validationErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('Se esperaba una ValidationException.');
}

beforeEach(function () {
    $this->type = quantityType(['name' => 'Guantes de rescate']);
    $this->warehouse = Location::factory()->create(['name' => 'Bodega principal']);
    $this->engine = Location::factory()->create(['name' => 'Máquina 1']);
});

it('US1-1: una entrada suma a la existencia y registra el movimiento con usuario y fecha', function () {
    $user = User::factory()->create();

    $movement = record(MovementType::Entry, $this->type, 20, to: $this->warehouse, reason: 'Compra', user: $user);

    expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(20)
        ->and($movement->fresh())
        ->type->toBe(MovementType::Entry)
        ->quantity->toBe(20)
        ->from_location_id->toBeNull()
        ->to_location_id->toBe($this->warehouse->id)
        ->reason->toBe('Compra')
        ->user_id->toBe($user->id)
        ->created_at->not->toBeNull();
});

it('US1-2 / FR-002: una salida que deja la existencia negativa se rechaza en quantity sin registrar nada', function () {
    record(MovementType::Entry, $this->type, 20, to: $this->warehouse);

    $errors = validationErrors(fn () => record(MovementType::Exit, $this->type, 25, from: $this->warehouse, reason: 'Consumo'));

    expect($errors)->toBe(['quantity' => ['Existencia insuficiente.']])
        ->and(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(20)
        ->and(InventoryMovement::query()->count())->toBe(1);

    record(MovementType::Exit, $this->type, 20, from: $this->warehouse, reason: 'Consumo');
    expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(0);
});

it('FR-002: una salida desde una ubicación sin existencia se rechaza', function () {
    expect(validationErrors(fn () => record(MovementType::Exit, $this->type, 1, from: $this->warehouse, reason: 'Daño')))
        ->toHaveKey('quantity');
});

it('US1-3: un traslado mueve la cantidad entre ubicaciones en una sola operación', function () {
    record(MovementType::Entry, $this->type, 20, to: $this->warehouse);

    record(MovementType::Transfer, $this->type, 5, from: $this->warehouse, to: $this->engine);

    expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(15)
        ->and(StockLevel::quantityOf($this->type, $this->engine))->toBe(5);

    expect(validationErrors(fn () => record(MovementType::Transfer, $this->type, 16, from: $this->warehouse, to: $this->engine)))
        ->toHaveKey('quantity')
        ->and(StockLevel::quantityOf($this->type, $this->engine))->toBe(5);
});

it('D-002: el ajuste suma con destino y resta con origen', function () {
    record(MovementType::Adjustment, $this->type, 4, to: $this->warehouse, reason: 'Sobrante');
    record(MovementType::Adjustment, $this->type, 1, from: $this->warehouse, reason: 'Faltante');

    expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(3)
        ->and(validationErrors(fn () => record(MovementType::Adjustment, $this->type, 4, from: $this->warehouse, reason: 'Faltante')))
        ->toHaveKey('quantity');
});

it('D-002 / RN-038: entrega, devolución y pérdida (para 007) con delivery_id', function () {
    record(MovementType::Entry, $this->type, 10, to: $this->warehouse);

    $deliveryId = Delivery::factory()->create()->id;

    record(MovementType::Delivery, $this->type, 3, from: $this->warehouse, deliveryId: $deliveryId);
    record(MovementType::Return, $this->type, 1, to: $this->engine, deliveryId: $deliveryId);
    $loss = record(MovementType::Loss, $this->type, 2, reason: 'Devuelto dañado', deliveryId: $deliveryId);

    expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(7)
        ->and(StockLevel::quantityOf($this->type, $this->engine))->toBe(1)
        ->and($loss->delivery_id)->toBe($deliveryId)
        ->and((int) StockLevel::query()->sum('quantity'))->toBe(8);
});

it('FR-001: los movimientos se corrigen con un movimiento inverso, nunca se editan ni se borran', function () {
    $movement = record(MovementType::Entry, $this->type, 10, to: $this->warehouse);

    expect(fn () => $movement->forceFill(['quantity' => 1])->save())->toThrow(LogicException::class)
        ->and(fn () => $movement->delete())->toThrow(LogicException::class);

    record(MovementType::Adjustment, $this->type, 10, from: $this->warehouse, reason: 'Entrada registrada por error');
    expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(0);
});

it('RN-007: solo los tipos por cantidad tienen existencias', function () {
    $serialized = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

    expect(validationErrors(fn () => record(MovementType::Entry, $serialized, 1, to: $this->warehouse)))
        ->toHaveKey('asset_type_id')
        ->and(StockLevel::query()->count())->toBe(0);
});

it('cantidades enteras positivas', function (int $quantity) {
    expect(validationErrors(fn () => record(MovementType::Entry, $this->type, $quantity, to: $this->warehouse)))
        ->toHaveKey('quantity');
})->with([0, -3]);

describe('D-003: tipos y ubicaciones inactivos', function () {
    it('una ubicación inactiva no recibe entradas ni traslados, pero sí puede vaciarse', function () {
        record(MovementType::Entry, $this->type, 6, to: $this->warehouse);
        $this->warehouse->forceFill(['is_active' => false])->save();

        expect(validationErrors(fn () => record(MovementType::Entry, $this->type, 1, to: $this->warehouse)))->toHaveKey('to_location_id')
            ->and(validationErrors(fn () => record(MovementType::Transfer, $this->type, 1, from: $this->engine, to: $this->warehouse)))->toHaveKey('to_location_id');

        record(MovementType::Transfer, $this->type, 4, from: $this->warehouse, to: $this->engine);
        record(MovementType::Exit, $this->type, 2, from: $this->warehouse, reason: 'Consumo');

        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(0)
            ->and(StockLevel::quantityOf($this->type, $this->engine))->toBe(4);
    });

    it('una ubicación de un tipo de ubicación inactivo tampoco recibe entradas', function () {
        $location = Location::factory()->for(LocationType::factory()->inactive())->create();

        expect(validationErrors(fn () => record(MovementType::Entry, $this->type, 1, to: $location)))->toHaveKey('to_location_id');
    });

    it('un tipo inactivo (o de categoría inactiva) no recibe entradas ni traslados y conserva su existencia', function () {
        record(MovementType::Entry, $this->type, 5, to: $this->warehouse);
        $this->type->forceFill(['is_active' => false])->save();

        expect(validationErrors(fn () => record(MovementType::Entry, $this->type, 1, to: $this->warehouse)))->toHaveKey('asset_type_id')
            ->and(validationErrors(fn () => record(MovementType::Transfer, $this->type, 1, from: $this->warehouse, to: $this->engine)))->toHaveKey('asset_type_id');

        record(MovementType::Exit, $this->type, 2, from: $this->warehouse, reason: 'Consumo');
        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(3);

        $inactiveCategory = quantityType(['asset_category_id' => AssetCategory::factory()->create(['is_active' => false])->id]);
        expect(validationErrors(fn () => record(MovementType::Entry, $inactiveCategory, 1, to: $this->warehouse)))->toHaveKey('asset_type_id');
    });

    it('B-5 (007): una devolución tampoco entra a una ubicación inactiva y no toca existencias', function () {
        $this->warehouse->forceFill(['is_active' => false])->save();

        expect(validationErrors(fn () => record(MovementType::Return, $this->type, 1, to: $this->warehouse)))->toHaveKey('to_location_id')
            ->and(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(0)
            ->and(InventoryMovement::query()->count())->toBe(0);
    });

    it('B-5 (007): la devolución sí admite un tipo inactivo (lo prestado vuelve aunque el tipo ya no se use)', function () {
        $this->type->forceFill(['is_active' => false])->save();

        record(MovementType::Return, $this->type, 2, to: $this->warehouse);

        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(2);
    });
});

it('D-002: rechaza combinaciones de ubicaciones que no corresponden a la clase', function (MovementType $type, bool $withFrom, bool $withTo) {
    expect(fn () => record($type, $this->type, 1, $withFrom ? $this->warehouse : null, $withTo ? $this->engine : null, 'Motivo'))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'entrada con origen' => [MovementType::Entry, true, true],
    'entrada sin destino' => [MovementType::Entry, false, false],
    'salida sin origen' => [MovementType::Exit, false, false],
    'salida con destino' => [MovementType::Exit, true, true],
    'traslado sin destino' => [MovementType::Transfer, true, false],
    'ajuste sin ubicación' => [MovementType::Adjustment, false, false],
    'ajuste con ambas' => [MovementType::Adjustment, true, true],
    'pérdida con ubicación' => [MovementType::Loss, true, false],
]);

it('D-002: un traslado exige ubicaciones distintas', function () {
    expect(fn () => record(MovementType::Transfer, $this->type, 1, $this->warehouse, $this->warehouse))
        ->toThrow(InvalidArgumentException::class);
});

it('SC-002: la suma de movimientos de un tipo en una ubicación coincide siempre con su existencia', function () {
    $other = quantityType();
    $locations = [$this->warehouse, $this->engine, Location::factory()->create()];
    $types = [$this->type, $other];
    mt_srand(31);

    foreach (range(1, 60) as $step) {
        $type = $types[mt_rand(0, 1)];
        $from = $locations[mt_rand(0, 2)];
        $to = $locations[mt_rand(0, 2)];
        $quantity = mt_rand(1, 8);

        try {
            match (mt_rand(0, 4)) {
                0, 1 => record(MovementType::Entry, $type, $quantity, to: $to),
                2 => record(MovementType::Exit, $type, $quantity, from: $from, reason: 'Consumo'),
                3 => $from->is($to) ? null : record(MovementType::Transfer, $type, $quantity, from: $from, to: $to),
                4 => record(MovementType::Adjustment, $type, $quantity, from: $from, reason: 'Ajuste'),
            };
        } catch (ValidationException) {
            // Existencia insuficiente: no cambia nada.
        }
    }

    $balances = DB::table('inventory_movements')
        ->selectRaw('asset_type_id, location_id, sum(delta)::int as total')
        ->fromSub(
            DB::table('inventory_movements')->selectRaw('asset_type_id, to_location_id as location_id, quantity as delta')->whereNotNull('to_location_id')
                ->unionAll(DB::table('inventory_movements')->selectRaw('asset_type_id, from_location_id, -quantity')->whereNotNull('from_location_id')),
            'inventory_movements',
        )
        ->groupBy('asset_type_id', 'location_id')
        ->get();

    expect($balances)->not->toBeEmpty();

    foreach ($balances as $balance) {
        expect(StockLevel::quantityOf($balance->asset_type_id, $balance->location_id))->toBe($balance->total);
    }

    expect(StockLevel::query()->where('quantity', '<', 0)->exists())->toBeFalse()
        ->and((int) StockLevel::query()->sum('quantity'))->toBe((int) $balances->sum('total'));
});
