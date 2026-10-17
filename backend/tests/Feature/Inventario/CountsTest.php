<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\AssetStatus;
use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;

/**
 * T005 — Conteos físicos por ubicación (US3, RN-027, RN-037, D-004, FR-004).
 * Contrato: specs/006-modulo-inventario/contracts/api.md.
 */
function countStock(AssetType $type, Location $location, int $quantity, MovementType $movement = MovementType::Entry): void
{
    $from = $movement === MovementType::Exit ? $location : null;
    $to = $movement === MovementType::Exit ? null : $location;

    app(RecordStockMovement::class)->handle($movement, $type, $quantity, $from, $to, 'Prueba', User::factory()->create());
}

function openCount(Location $location, array $payload = []): array
{
    return test()->postJson('/api/inventory/counts', ['location_id' => $location->id, ...$payload])->assertCreated()->json('data');
}

/**
 * @param  array<string, mixed>  $count
 * @return array<string, mixed>
 */
function lineFor(array $count, string $name): array
{
    return collect($count['lines'])->firstOrFail(fn (array $line) => ($line['asset']['plate'] ?? $line['asset_type']['name']) === $name);
}

beforeEach(function () {
    $this->user = actingAsRole('almacenista');
    $this->warehouse = Location::factory()->create(['name' => 'Bodega principal']);
    $this->gloves = AssetType::factory()->create(['name' => 'Guantes de rescate', 'tracking_mode' => TrackingMode::Quantity]);
    $this->rope = AssetType::factory()->create(['name' => 'Cuerda', 'tracking_mode' => TrackingMode::Quantity]);
    $this->helmet = AssetType::factory()->create(['name' => 'Casco', 'tracking_mode' => TrackingMode::Serialized]);
});

describe('POST /api/inventory/counts', function () {
    it('D-004: abre el conteo con lo que debería haber en la ubicación', function () {
        countStock($this->gloves, $this->warehouse, 15);
        countStock($this->rope, $this->warehouse, 2);
        countStock($this->rope, $this->warehouse, 2, MovementType::Exit);
        countStock($this->rope, Location::factory()->create(), 3);
        Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->create(['plate' => 'B31-001']);
        Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->inMaintenance()->create(['plate' => 'B31-002']);
        // RN-039: los asignados están con el empleado; los dados de baja ya no cuentan.
        Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->assignedTo()->create(['plate' => 'B31-003']);
        Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->retired()->create(['plate' => 'B31-004']);
        Asset::factory()->for($this->helmet, 'assetType')->at(Location::factory()->create())->create(['plate' => 'B31-005']);

        $response = $this->postJson('/api/inventory/counts', ['location_id' => $this->warehouse->id, 'notes' => 'Conteo mensual'])
            ->assertCreated()
            ->assertJsonPath('data.location', ['id' => $this->warehouse->id, 'name' => 'Bodega principal'])
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.notes', 'Conteo mensual')
            ->assertJsonPath('data.opened_by', ['id' => $this->user->id, 'name' => $this->user->name])
            ->assertJsonPath('data.closed_by', null)
            ->assertJsonPath('data.closed_at', null)
            ->assertJsonCount(3, 'data.lines');

        $count = $response->json('data');
        expect(lineFor($count, 'Guantes de rescate'))->toMatchArray([
            'asset_type' => ['id' => $this->gloves->id, 'name' => 'Guantes de rescate'],
            'asset' => null, 'expected_quantity' => 15, 'counted_quantity' => null, 'found' => null,
        ])
            ->and(lineFor($count, 'B31-001'))->toMatchArray([
                'asset_type' => ['id' => $this->helmet->id, 'name' => 'Casco'],
                'expected_quantity' => null, 'counted_quantity' => null, 'found' => null,
            ])
            ->and(lineFor($count, 'B31-001')['asset'])->toMatchArray(['plate' => 'B31-001'])
            ->and(lineFor($count, 'B31-002'))->toBeArray();
    });

    it('US3-3: no abre un segundo conteo en la misma ubicación', function () {
        openCount($this->warehouse);

        $this->postJson('/api/inventory/counts', ['location_id' => $this->warehouse->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['location_id' => 'Ya hay un conteo abierto en esta ubicación.']);

        expect(InventoryCount::query()->count())->toBe(1);
    });

    it('valida ubicación y notas', function () {
        $this->postJson('/api/inventory/counts', [])->assertJsonValidationErrors('location_id');
        $this->postJson('/api/inventory/counts', ['location_id' => 999999])->assertJsonValidationErrors('location_id');
        $this->postJson('/api/inventory/counts', ['location_id' => $this->warehouse->id, 'notes' => str_repeat('x', 501)])
            ->assertJsonValidationErrors('notes');
    });
});

describe('GET /api/inventory/counts', function () {
    it('lista sin renglones, filtra por estado y ubicación y muestra el detalle', function () {
        $other = Location::factory()->create();
        $first = openCount($this->warehouse);
        $second = openCount($other);
        $this->postJson("/api/inventory/counts/{$first['id']}/close")->assertOk();

        actingAsRole('consulta');

        $this->getJson('/api/inventory/counts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $second['id'])
            ->assertJsonPath('data.1.id', $first['id'])
            ->assertJsonMissingPath('data.0.lines')
            ->assertJsonPath('meta.total', 2);

        expect(collect($this->getJson('/api/inventory/counts?status=closed')->json('data'))->pluck('id')->all())->toBe([$first['id']])
            ->and(collect($this->getJson("/api/inventory/counts?location_id={$other->id}")->json('data'))->pluck('id')->all())->toBe([$second['id']]);

        $this->getJson("/api/inventory/counts/{$first['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_by.id', $this->user->id)
            ->assertJsonPath('data.lines', []);
    });

    it('FR-006: ver exige inventario.ver y abrir exige inventario.gestionar', function () {
        $count = openCount($this->warehouse);

        actingAsRole('consulta');
        $this->postJson('/api/inventory/counts', ['location_id' => Location::factory()->create()->id])->assertForbidden();
        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => []])->assertForbidden();
        $this->postJson("/api/inventory/counts/{$count['id']}/close")->assertForbidden();

        actingWithPermissions(['activos.ver']);
        $this->getJson('/api/inventory/counts')->assertForbidden();
        $this->getJson("/api/inventory/counts/{$count['id']}")->assertForbidden();
    });
});

describe('PATCH /api/inventory/counts/{id}/lines', function () {
    it('registra contados, encontrados y agrega tipos sin existencia', function () {
        countStock($this->gloves, $this->warehouse, 15);
        Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->create(['plate' => 'B31-001']);
        $count = openCount($this->warehouse);
        $gloves = lineFor($count, 'Guantes de rescate');
        $helmet = lineFor($count, 'B31-001');

        $response = $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['id' => $gloves['id'], 'counted_quantity' => 13],
            ['id' => $helmet['id'], 'asset_id' => $helmet['asset']['id'], 'found' => true],
            ['asset_type_id' => $this->rope->id, 'counted_quantity' => 4],
        ]])->assertOk()->assertJsonCount(3, 'data.lines');

        $updated = $response->json('data');
        expect(lineFor($updated, 'Guantes de rescate')['counted_quantity'])->toBe(13)
            ->and(lineFor($updated, 'B31-001')['found'])->toBeTrue()
            ->and(lineFor($updated, 'Cuerda'))->toMatchArray(['expected_quantity' => 0, 'counted_quantity' => 4, 'asset' => null]);

        // Se puede volver a dejar sin contar y corregir lo agregado.
        $rope = lineFor($updated, 'Cuerda');
        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['id' => $gloves['id'], 'counted_quantity' => null],
            ['id' => $rope['id'], 'counted_quantity' => 5],
        ]])->assertOk();

        expect(InventoryCountLine::query()->find($gloves['id'])->counted_quantity)->toBeNull()
            ->and(InventoryCountLine::query()->find($rope['id'])->counted_quantity)->toBe(5)
            ->and(StockLevel::quantityOf($this->gloves, $this->warehouse))->toBe(15);
    });

    it('errores por renglón con la clave lines.N.campo', function () {
        countStock($this->gloves, $this->warehouse, 15);
        Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->create(['plate' => 'B31-001']);
        $count = openCount($this->warehouse);
        $foreignLine = InventoryCountLine::query()->create([
            'count_id' => InventoryCount::factory()->create()->id, 'asset_type_id' => $this->rope->id, 'expected_quantity' => 0,
        ]);
        $gloves = lineFor($count, 'Guantes de rescate');
        $helmet = lineFor($count, 'B31-001');

        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['id' => $gloves['id'], 'counted_quantity' => -1],
            ['id' => $foreignLine->id, 'counted_quantity' => 1],
            ['id' => $helmet['id'], 'asset_id' => $helmet['asset']['id'], 'found' => 'quizá'],
            ['asset_type_id' => $this->helmet->id, 'counted_quantity' => 1],
            ['asset_type_id' => $this->gloves->id, 'counted_quantity' => 1],
            ['asset_type_id' => 999999, 'counted_quantity' => 1],
            ['id' => $gloves['id'], 'found' => true],
            ['id' => $helmet['id'], 'asset_id' => $helmet['asset']['id'] + 1000, 'found' => true],
            ['counted_quantity' => 2],
        ]])
            ->assertUnprocessable()
            ->assertOnlyJsonValidationErrors([
                'lines.0.counted_quantity', 'lines.1.id', 'lines.2.found', 'lines.3.asset_type_id',
                'lines.4.asset_type_id', 'lines.5.asset_type_id', 'lines.6.found', 'lines.7.asset_id', 'lines.8.asset_type_id',
            ]);

        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", [])->assertJsonValidationErrors('lines');
        expect(InventoryCountLine::query()->find($gloves['id'])->counted_quantity)->toBeNull();
    });

    it('un tipo nuevo no puede ser serializado ni estar inactivo', function () {
        $count = openCount($this->warehouse);
        $this->rope->forceFill(['is_active' => false])->save();

        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['asset_type_id' => $this->rope->id, 'counted_quantity' => 1],
        ]])->assertJsonValidationErrors('lines.0.asset_type_id');
    });
});

describe('POST /api/inventory/counts/{id}/close', function () {
    it('US3-1 / RN-027: las diferencias se registran como ajustes con referencia al conteo', function () {
        countStock($this->gloves, $this->warehouse, 15);
        countStock($this->rope, $this->warehouse, 2);
        $count = openCount($this->warehouse);

        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['id' => lineFor($count, 'Guantes de rescate')['id'], 'counted_quantity' => 13],
            ['id' => lineFor($count, 'Cuerda')['id'], 'counted_quantity' => 2],
        ]])->assertOk();
        $lantern = AssetType::factory()->create(['name' => 'Linterna', 'tracking_mode' => TrackingMode::Quantity]);
        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['asset_type_id' => $lantern->id, 'counted_quantity' => 3],
        ]])->assertOk();

        $this->postJson("/api/inventory/counts/{$count['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.closed_by', ['id' => $this->user->id, 'name' => $this->user->name])
            ->assertJsonPath('data.changed_since_open', false)
            ->assertJsonStructure(['data' => ['closed_at']]);

        expect(StockLevel::quantityOf($this->gloves, $this->warehouse))->toBe(13)
            ->and(StockLevel::quantityOf($this->rope, $this->warehouse))->toBe(2)
            ->and(StockLevel::quantityOf($lantern, $this->warehouse))->toBe(3);

        $adjustments = InventoryMovement::query()->where('count_id', $count['id'])->orderBy('asset_type_id')->get();
        expect($adjustments)->toHaveCount(2)
            ->and($adjustments->every(fn (InventoryMovement $movement) => $movement->type === MovementType::Adjustment))->toBeTrue()
            ->and($adjustments->firstWhere('asset_type_id', $this->gloves->id))
            ->quantity->toBe(2)
            ->from_location_id->toBe($this->warehouse->id)
            ->to_location_id->toBeNull()
            ->user_id->toBe($this->user->id)
            ->reason->toContain((string) $count['id'])
            ->and($adjustments->firstWhere('asset_type_id', $lantern->id))
            ->quantity->toBe(3)
            ->to_location_id->toBe($this->warehouse->id);

        $this->getJson('/api/inventory/movements?type=adjustment')->assertJsonPath('data.0.count_id', $count['id']);
    });

    it('US3-2 / RN-037: un serializado no encontrado solo se reporta; no cambia de estado', function () {
        $found = Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->create(['plate' => 'B31-001']);
        $missing = Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->inMaintenance()->create(['plate' => 'B31-002']);
        $unchecked = Asset::factory()->for($this->helmet, 'assetType')->at($this->warehouse)->create(['plate' => 'B31-003']);
        $count = openCount($this->warehouse);

        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [
            ['id' => lineFor($count, 'B31-001')['id'], 'asset_id' => $found->id, 'found' => true],
            ['id' => lineFor($count, 'B31-002')['id'], 'asset_id' => $missing->id, 'found' => false],
        ]])->assertOk();

        $closed = $this->postJson("/api/inventory/counts/{$count['id']}/close")->assertOk()->json('data');

        expect(lineFor($closed, 'B31-001')['found'])->toBeTrue()
            ->and(lineFor($closed, 'B31-002')['found'])->toBeFalse()
            ->and(lineFor($closed, 'B31-003')['found'])->toBeFalse()
            ->and($missing->fresh()->status)->toBe(AssetStatus::Maintenance)
            ->and($missing->fresh()->location_id)->toBe($this->warehouse->id)
            ->and($unchecked->fresh()->status)->toBe(AssetStatus::Available)
            ->and($missing->events()->exists())->toBeFalse()
            ->and(InventoryMovement::query()->exists())->toBeFalse();
    });

    it('US3-4: si hubo movimientos durante el conteo, recalcula lo esperado y lo advierte', function () {
        countStock($this->gloves, $this->warehouse, 15);
        $count = openCount($this->warehouse);
        $line = lineFor($count, 'Guantes de rescate');
        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [['id' => $line['id'], 'counted_quantity' => 10]]])->assertOk();

        countStock($this->gloves, $this->warehouse, 3, MovementType::Exit);

        $this->postJson("/api/inventory/counts/{$count['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.changed_since_open', true)
            ->assertJsonPath('data.lines.0.expected_quantity', 12)
            ->assertJsonPath('data.lines.0.counted_quantity', 10);

        expect(StockLevel::quantityOf($this->gloves, $this->warehouse))->toBe(10)
            ->and(InventoryMovement::query()->where('count_id', $count['id'])->sole()->quantity)->toBe(2);
    });

    it('D-004: no cierra con renglones de cantidad sin contar', function () {
        countStock($this->gloves, $this->warehouse, 15);
        $count = openCount($this->warehouse);

        $this->postJson("/api/inventory/counts/{$count['id']}/close")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines' => 'Registra la cantidad contada de todos los renglones antes de cerrar.']);

        expect(InventoryCount::query()->find($count['id'])->isOpen())->toBeTrue();
    });

    it('un conteo cerrado no se modifica ni se vuelve a cerrar (409) y libera la ubicación', function () {
        $count = openCount($this->warehouse);
        $this->postJson("/api/inventory/counts/{$count['id']}/close")->assertOk();

        $this->patchJson("/api/inventory/counts/{$count['id']}/lines", ['lines' => [['asset_type_id' => $this->rope->id, 'counted_quantity' => 1]]])
            ->assertConflict()
            ->assertJsonPath('message', 'El conteo ya está cerrado.');
        $this->postJson("/api/inventory/counts/{$count['id']}/close")->assertConflict();

        openCount($this->warehouse);
    });
});
