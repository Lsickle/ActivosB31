<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Models\AssetType;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * T004 — GET/POST /api/inventory/movements (US1, FR-001, FR-003, FR-006,
 * D-002, D-003). Contrato: specs/006-modulo-inventario/contracts/api.md.
 */
function stockType(array $attributes = []): AssetType
{
    return AssetType::factory()->create(['tracking_mode' => TrackingMode::Quantity, ...$attributes]);
}

function seedStock(AssetType $type, Location $location, int $quantity): InventoryMovement
{
    return app(RecordStockMovement::class)->handle(MovementType::Entry, $type, $quantity, null, $location, null, User::factory()->create());
}

beforeEach(function () {
    $this->type = stockType(['name' => 'Guantes de rescate']);
    $this->warehouse = Location::factory()->create(['name' => 'Bodega principal']);
    $this->engine = Location::factory()->create(['name' => 'Máquina 1']);
});

describe('POST /api/inventory/movements', function () {
    it('US1-1: registra una entrada con usuario y fecha', function () {
        $user = actingAsRole('almacenista');

        $this->postJson('/api/inventory/movements', [
            'type' => 'entry', 'asset_type_id' => $this->type->id, 'quantity' => 20,
            'to_location_id' => $this->warehouse->id, 'reason' => null,
        ])
            ->assertCreated()
            ->assertJsonPath('data.type', 'entry')
            ->assertJsonPath('data.type_label', 'Entrada')
            ->assertJsonPath('data.asset_type', ['id' => $this->type->id, 'name' => 'Guantes de rescate'])
            ->assertJsonPath('data.quantity', 20)
            ->assertJsonPath('data.from_location', null)
            ->assertJsonPath('data.to_location', ['id' => $this->warehouse->id, 'name' => 'Bodega principal'])
            ->assertJsonPath('data.reason', null)
            ->assertJsonPath('data.delivery_id', null)
            ->assertJsonPath('data.count_id', null)
            ->assertJsonPath('data.user', ['id' => $user->id, 'name' => $user->name])
            ->assertJsonStructure(['data' => ['id', 'created_at']]);

        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(20);
    });

    it('US1-2: una salida mayor que la existencia responde 422 en quantity', function () {
        actingAsRole('almacenista');
        seedStock($this->type, $this->warehouse, 20);

        $this->postJson('/api/inventory/movements', [
            'type' => 'exit', 'asset_type_id' => $this->type->id, 'quantity' => 25,
            'from_location_id' => $this->warehouse->id, 'reason' => 'Consumo',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['quantity' => 'Existencia insuficiente.']);

        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(20);
    });

    it('US1-3: traslada entre ubicaciones', function () {
        actingAsRole('almacenista');
        seedStock($this->type, $this->warehouse, 20);

        $this->postJson('/api/inventory/movements', [
            'type' => 'transfer', 'asset_type_id' => $this->type->id, 'quantity' => 5,
            'from_location_id' => $this->warehouse->id, 'to_location_id' => $this->engine->id,
        ])->assertCreated()->assertJsonPath('data.from_location.id', $this->warehouse->id);

        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(15)
            ->and(StockLevel::quantityOf($this->type, $this->engine))->toBe(5);
    });

    it('D-002: valida las ubicaciones y el motivo según la clase', function (array $payload, array $errors) {
        actingAsRole('almacenista');
        $ids = ['W' => $this->warehouse->id, 'E' => $this->engine->id];
        $payload = array_map(fn ($value) => is_string($value) && isset($ids[$value]) ? $ids[$value] : $value, $payload);

        $this->postJson('/api/inventory/movements', ['asset_type_id' => $this->type->id, 'quantity' => 1, ...$payload])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors($errors);
    })->with([
        'entrada sin destino' => [['type' => 'entry'], ['to_location_id']],
        'entrada con origen' => [['type' => 'entry', 'to_location_id' => 'W', 'from_location_id' => 'E'], ['from_location_id']],
        'salida sin origen ni motivo' => [['type' => 'exit'], ['from_location_id', 'reason']],
        'salida con destino' => [['type' => 'exit', 'from_location_id' => 'W', 'to_location_id' => 'E', 'reason' => 'x'], ['to_location_id']],
        'traslado a la misma ubicación' => [['type' => 'transfer', 'from_location_id' => 'W', 'to_location_id' => 'W'], ['to_location_id']],
        'traslado sin origen' => [['type' => 'transfer', 'to_location_id' => 'W'], ['from_location_id']],
        'ajuste sin ubicación' => [['type' => 'adjustment', 'reason' => 'x'], ['to_location_id']],
        'ajuste con ambas' => [['type' => 'adjustment', 'from_location_id' => 'W', 'to_location_id' => 'E', 'reason' => 'x'], ['to_location_id']],
        'ajuste sin motivo' => [['type' => 'adjustment', 'to_location_id' => 'W', 'reason' => '  '], ['reason']],
        'clase de Entregas' => [['type' => 'delivery', 'from_location_id' => 'W'], ['type']],
        'clase desconocida' => [['type' => 'gift', 'to_location_id' => 'W'], ['type']],
        'cantidad cero' => [['type' => 'entry', 'to_location_id' => 'W', 'quantity' => 0], ['quantity']],
        'cantidad decimal' => [['type' => 'entry', 'to_location_id' => 'W', 'quantity' => 1.5], ['quantity']],
        'motivo largo' => [['type' => 'entry', 'to_location_id' => 'W', 'reason' => str_repeat('x', 501)], ['reason']],
        'ubicación inexistente' => [['type' => 'entry', 'to_location_id' => 999999], ['to_location_id']],
    ]);

    it('D-002: el ajuste admite exactamente una ubicación (destino suma, origen resta)', function () {
        actingAsRole('almacenista');

        $this->postJson('/api/inventory/movements', [
            'type' => 'adjustment', 'asset_type_id' => $this->type->id, 'quantity' => 3, 'to_location_id' => $this->warehouse->id, 'reason' => 'Sobrante',
        ])->assertCreated();
        $this->postJson('/api/inventory/movements', [
            'type' => 'adjustment', 'asset_type_id' => $this->type->id, 'quantity' => 1, 'from_location_id' => $this->warehouse->id, 'reason' => 'Faltante',
        ])->assertCreated()->assertJsonPath('data.reason', 'Faltante');

        expect(StockLevel::quantityOf($this->type, $this->warehouse))->toBe(2);
    });

    it('US1-6 / RN-007: rechaza tipos serializados y tipos inexistentes', function () {
        actingAsRole('almacenista');
        $serialized = AssetType::factory()->create(['tracking_mode' => TrackingMode::Serialized]);

        foreach ([$serialized->id, 999999] as $typeId) {
            $this->postJson('/api/inventory/movements', [
                'type' => 'entry', 'asset_type_id' => $typeId, 'quantity' => 1, 'to_location_id' => $this->warehouse->id,
            ])->assertUnprocessable()->assertOnlyJsonValidationErrors(['asset_type_id']);
        }
    });

    it('D-003: una ubicación inactiva no recibe entradas, pero sí puede vaciarse', function () {
        actingAsRole('almacenista');
        seedStock($this->type, $this->warehouse, 4);
        $this->warehouse->forceFill(['is_active' => false])->save();

        $this->postJson('/api/inventory/movements', [
            'type' => 'entry', 'asset_type_id' => $this->type->id, 'quantity' => 1, 'to_location_id' => $this->warehouse->id,
        ])->assertUnprocessable()->assertOnlyJsonValidationErrors(['to_location_id']);

        $this->postJson('/api/inventory/movements', [
            'type' => 'exit', 'asset_type_id' => $this->type->id, 'quantity' => 4, 'from_location_id' => $this->warehouse->id, 'reason' => 'Baja',
        ])->assertCreated();
    });

    it('FR-006: registrar exige inventario.gestionar', function () {
        actingAsRole('consulta');

        $this->postJson('/api/inventory/movements', [
            'type' => 'entry', 'asset_type_id' => $this->type->id, 'quantity' => 1, 'to_location_id' => $this->warehouse->id,
        ])->assertForbidden();

        expect(InventoryMovement::query()->exists())->toBeFalse();
    });

    it('US1-4: no existen rutas para editar ni borrar movimientos', function () {
        actingAsRole('admin');
        $movement = seedStock($this->type, $this->warehouse, 2);

        $this->patchJson("/api/inventory/movements/{$movement->id}", ['quantity' => 1])->assertNotFound();
        $this->deleteJson("/api/inventory/movements/{$movement->id}")->assertNotFound();
    });
});

describe('GET /api/inventory/movements', function () {
    it('FR-003: lista del más reciente al más antiguo con el formato del contrato', function () {
        actingAsRole('consulta');
        $first = seedStock($this->type, $this->warehouse, 5);
        $second = seedStock($this->type, $this->engine, 2);

        $this->getJson('/api/inventory/movements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.1.type_label', 'Entrada')
            ->assertJsonPath('data.1.user.id', $first->user_id)
            ->assertJsonPath('meta.total', 2);
    });

    it('FR-003: filtra por clase, tipo y ubicación (origen o destino)', function () {
        actingAsRole('consulta');
        $other = stockType();
        $entry = seedStock($this->type, $this->warehouse, 5);
        $transfer = app(RecordStockMovement::class)->handle(MovementType::Transfer, $this->type, 2, $this->warehouse, $this->engine, null, User::factory()->create());
        $otherEntry = seedStock($other, $this->engine, 1);

        $ids = fn (string $query) => collect($this->getJson("/api/inventory/movements?{$query}")->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('type=transfer'))->toBe([$transfer->id])
            ->and($ids("asset_type_id={$other->id}"))->toBe([$otherEntry->id])
            ->and($ids("location_id={$this->warehouse->id}"))->toBe([$transfer->id, $entry->id])
            ->and($ids("location_id={$this->engine->id}"))->toBe([$otherEntry->id, $transfer->id]);
    });

    it('FR-003: el rango de fechas es inclusivo y se interpreta en America/Bogota', function () {
        actingAsRole('consulta');
        // 23:30 del 16 en Bogotá = 04:30 UTC del 17.
        $this->travelTo(Carbon::parse('2026-10-16 23:30', 'America/Bogota'));
        $late = seedStock($this->type, $this->warehouse, 1);
        $this->travelTo(Carbon::parse('2026-10-17 00:10', 'America/Bogota'));
        $next = seedStock($this->type, $this->warehouse, 1);
        $this->travelBack();

        $ids = fn (string $query) => collect($this->getJson("/api/inventory/movements?{$query}")->assertOk()->json('data'))->pluck('id')->all();

        expect($ids('from=2026-10-16&to=2026-10-16'))->toBe([$late->id])
            ->and($ids('from=2026-10-17'))->toBe([$next->id])
            ->and($ids('to=2026-10-17'))->toBe([$next->id, $late->id]);

        $this->getJson('/api/inventory/movements?from=2026-10-18&to=2026-10-17')->assertJsonValidationErrors('to');
        $this->getJson('/api/inventory/movements?from=17/10/2026')->assertJsonValidationErrors('from');
        $this->getJson('/api/inventory/movements?per_page=101')->assertJsonValidationErrors('per_page');
    });

    it('FR-006: ver exige inventario.ver', function () {
        actingWithPermissions(['activos.ver']);

        $this->getJson('/api/inventory/movements')->assertForbidden();
    });
});
