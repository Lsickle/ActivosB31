<?php

use App\Actions\Inventario\RecordStockMovement;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Models\Asset;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\InventoryMovement;
use App\Models\StockLevel;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * T004 — POST /api/deliveries (FR-001, FR-002, D-002, D-003; RN-023..RN-025,
 * RN-039): todo o nada, firma obligatoria, activos disponibles, existencia
 * suficiente.
 */
beforeEach(function () {
    Storage::fake('local');
    Carbon::setTestNow(Carbon::parse('2026-10-20 15:00:00', 'UTC'));

    $this->scenario = deliveryScenario();
    $this->user = actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * Nada quedó registrado: ni entrega, ni asignación, ni movimientos, ni firma.
 */
function expectNothingDelivered(array $scenario): void
{
    expect(Delivery::query()->count())->toBe(0)
        ->and($scenario['helmet']->fresh()->status)->toBe(AssetStatus::Available)
        ->and($scenario['helmet']->fresh()->holder_employee_id)->toBeNull()
        ->and($scenario['helmet']->events()->count())->toBe(0)
        ->and(StockLevel::quantityOf($scenario['gloves'], $scenario['warehouse']))->toBe(20)
        ->and(InventoryMovement::query()->where('type', MovementType::Delivery)->count())->toBe(0)
        ->and(Storage::disk('local')->allFiles('signatures'))->toBe([]);
}

it('US1-1 / RN-025 / RN-039: entrega un casco y 2 pares de guantes con firma', function () {
    $response = $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['notes' => '  Dotación anual  ']))
        ->assertCreated()
        ->assertJsonPath('data.kind', 'dotacion')
        ->assertJsonPath('data.kind_label', 'Dotación')
        ->assertJsonPath('data.due_on', null)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.notes', 'Dotación anual')
        ->assertJsonPath('data.employee', ['id' => $this->scenario['employee']->id, 'full_name' => 'Ana Rojas', 'position' => $this->scenario['employee']->position->name])
        ->assertJsonPath('data.from_location', ['id' => $this->scenario['warehouse']->id, 'name' => 'Bodega'])
        ->assertJsonPath('data.delivered_by', ['id' => $this->user->id, 'name' => $this->user->name])
        ->assertJsonPath('data.items.0.asset', ['id' => $this->scenario['helmet']->id, 'plate' => 'CAS-001'])
        ->assertJsonPath('data.items.0.quantity', 1)
        ->assertJsonPath('data.items.0.pending_quantity', 1)
        ->assertJsonPath('data.items.1.asset', null)
        ->assertJsonPath('data.items.1.asset_type', ['id' => $this->scenario['gloves']->id, 'name' => 'Guantes', 'tracking_mode' => 'quantity'])
        ->assertJsonPath('data.items.1.quantity', 2)
        ->assertJsonPath('data.items.1.returned_quantity', 0)
        ->assertJsonPath('data.items.1.returns', []);

    $delivery = Delivery::query()->sole();
    $helmet = $this->scenario['helmet']->fresh();

    expect($response->json('data.code'))->toBe($delivery->code)->toMatch('/^ENT-\d{6}$/')
        ->and($response->json('data.signature_url'))->toContain("signatures/{$delivery->id}.png")
        ->and($response->json('data.employee'))->toHaveKeys(['id', 'full_name', 'position'])->toHaveCount(3)
        ->and($helmet->status)->toBe(AssetStatus::Assigned)
        ->and($helmet->holder_employee_id)->toBe($this->scenario['employee']->id)
        ->and($helmet->location_id)->toBe($this->scenario['warehouse']->id)
        ->and(StockLevel::quantityOf($this->scenario['gloves'], $this->scenario['warehouse']))->toBe(18);

    $event = $helmet->events()->sole();
    expect($event->event)->toBe(AssetEventType::Delivered)
        ->and($event->delivery_id)->toBe($delivery->id)
        ->and($event->employee_id)->toBe($this->scenario['employee']->id);

    $movement = InventoryMovement::query()->where('type', MovementType::Delivery)->sole();
    expect($movement->delivery_id)->toBe($delivery->id)
        ->and($movement->quantity)->toBe(2)
        ->and($movement->from_location_id)->toBe($this->scenario['warehouse']->id)
        ->and($movement->user_id)->toBe($this->user->id);

    // D-002: firma re-codificada en el disco privado.
    expect($delivery->signature_path)->toBe("signatures/{$delivery->id}.png")
        ->and(getimagesizefromstring(Storage::disk('local')->get($delivery->signature_path))[2])->toBe(IMAGETYPE_PNG);
});

it('D-001: los consecutivos se asignan en orden', function () {
    $first = $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertCreated()->json('data.code');
    $second = $this->postJson('/api/deliveries', deliveryPayload($this->scenario, [
        'items' => [['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 1]],
    ]))->assertCreated()->json('data.code');

    expect((int) substr($second, 4))->toBe((int) substr($first, 4) + 1);
});

it('RN-025: una entrega solo con artículos por cantidad o solo con serializados', function () {
    $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [['asset_id' => $this->scenario['helmet']->id]]]))
        ->assertCreated();
    $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 20]]]))
        ->assertCreated();

    expect(StockLevel::quantityOf($this->scenario['gloves'], $this->scenario['warehouse']))->toBe(0);
});

describe('RN-023: préstamo y dotación', function () {
    it('US1-2: el préstamo exige la fecha de devolución esperada', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['kind' => 'prestamo', 'due_on' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_on']);

        expectNothingDelivered($this->scenario);
    });

    it('la fecha esperada no puede ser anterior a hoy en America/Bogota', function () {
        // 2026-10-20 03:00 UTC = 2026-10-19 en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['kind' => 'prestamo', 'due_on' => '2026-10-18']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['due_on']);

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['kind' => 'prestamo', 'due_on' => '2026-10-19']))
            ->assertCreated()
            ->assertJsonPath('data.kind', 'prestamo')
            ->assertJsonPath('data.kind_label', 'Préstamo')
            ->assertJsonPath('data.due_on', '2026-10-19')
            ->assertJsonPath('data.status', 'open');
    });

    it('en dotación la fecha esperada se ignora', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['kind' => 'dotacion', 'due_on' => '2020-01-01']))
            ->assertCreated()
            ->assertJsonPath('data.due_on', null);

        expect(Delivery::query()->sole()->due_on)->toBeNull();
    });

    it('el tipo es obligatorio y solo dotación o préstamo', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['kind' => 'regalo']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['kind']);
    });
});

describe('RN-024 / US1-3: firma obligatoria', function () {
    it('sin firma no se guarda', function (?string $signature) {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['signature' => $signature]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature']);

        expectNothingDelivered($this->scenario);
    })->with([null, '']);

    it('D-002: una firma que no es PNG válido no se guarda', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['signature' => signatureDataUrl('no es una imagen')]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature']);

        expectNothingDelivered($this->scenario);
    });

    it('D-002: rechaza un data URL más largo que 300 KB codificados sin decodificarlo', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['signature' => 'data:image/png;base64,'.str_repeat('A', 409_604)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['signature']);
    });
});

describe('US1-4: solo activos disponibles', function () {
    it('rechaza activos asignados, en mantenimiento o dados de baja', function (string $state) {
        $asset = match ($state) {
            'assigned' => Asset::factory()->assignedTo()->create(),
            'maintenance' => Asset::factory()->inMaintenance()->create(),
            'retired' => Asset::factory()->retired()->create(),
        };

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [
            ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 2],
            ['asset_id' => $asset->id],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.asset_id' => 'Solo se pueden entregar activos disponibles.']);

        expectNothingDelivered($this->scenario);
    })->with(['assigned', 'maintenance', 'retired']);

    it('rechaza activos inexistentes y el mismo activo dos veces', function () {
        $helmet = ['asset_id' => $this->scenario['helmet']->id];

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [['asset_id' => 999_999]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.asset_id']);

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [$helmet, $helmet]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.asset_id']);

        expectNothingDelivered($this->scenario);
    });
});

describe('US1-5: existencias por cantidad', function () {
    it('cantidades mayores a la existencia en el origen rechazan toda la entrega', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [
            ['asset_id' => $this->scenario['helmet']->id],
            ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 21],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.quantity' => RecordStockMovement::INSUFFICIENT_STOCK_MESSAGE]);

        expectNothingDelivered($this->scenario);
    });

    it('la existencia es la de la ubicación de origen', function () {
        $other = deliveryScenario(gloves: 0, suffix: '-2');

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, [
            'from_location_id' => $other['warehouse']->id,
            'items' => [['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 1]],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.quantity' => RecordStockMovement::INSUFFICIENT_STOCK_MESSAGE]);
    });

    it('RN-007: un tipo serializado no se entrega por cantidad; la cantidad es un entero positivo', function () {
        $serializedType = $this->scenario['helmet']->assetType;

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [
            ['asset_type_id' => $serializedType->id, 'quantity' => 1],
            ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 0],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.asset_type_id', 'items.1.quantity']);

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [
            ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 1],
            ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 1],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.asset_type_id']);

        expectNothingDelivered($this->scenario);
    });

    it('un artículo es un activo o un tipo con cantidad, no ambos', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['items' => [
            ['asset_id' => $this->scenario['helmet']->id, 'asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 1],
            [],
        ]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.0.asset_id', 'items.1.asset_id']);
    });
});

describe('validación general', function () {
    it('empleado, origen y al menos un artículo son obligatorios', function () {
        $this->postJson('/api/deliveries', ['signature' => signatureDataUrl(), 'kind' => 'dotacion', 'items' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id', 'from_location_id', 'items']);
    });

    it('US1-4 de 004: un empleado inactivo no recibe entregas', function () {
        $inactive = Employee::factory()->inactive()->create();

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['employee_id' => $inactive->id]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['employee_id' => 'El empleado está inactivo.']);

        expectNothingDelivered($this->scenario);
    });

    it('las observaciones admiten máximo 1000 caracteres', function () {
        $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['notes' => str_repeat('a', 1001)]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['notes']);
    });
});

describe('D-003: atomicidad', function () {
    it('un error al mover existencias deshace la asignación y no deja la firma', function () {
        $this->mock(RecordStockMovement::class)
            ->shouldReceive('handle')
            ->andThrow(new RuntimeException('Falla simulada'));

        $this->withoutExceptionHandling();

        expect(fn () => $this->postJson('/api/deliveries', deliveryPayload($this->scenario)))
            ->toThrow(RuntimeException::class, 'Falla simulada');

        expectNothingDelivered($this->scenario);
    });

    it('existencia insuficiente detectada dentro de la transacción (carrera) se informa en el artículo', function () {
        $this->mock(RecordStockMovement::class)
            ->shouldReceive('handle')
            ->andThrow(ValidationException::withMessages(['quantity' => [RecordStockMovement::INSUFFICIENT_STOCK_MESSAGE]]));

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['items.1.quantity' => RecordStockMovement::INSUFFICIENT_STOCK_MESSAGE]);

        expectNothingDelivered($this->scenario);
    });

    it('un fallo al guardar la firma deshace toda la entrega', function () {
        $disk = Mockery::mock(Storage::disk('local'));
        $disk->shouldReceive('put')->andReturn(false);
        Storage::set('local', $disk);

        $this->withoutExceptionHandling();

        expect(fn () => $this->postJson('/api/deliveries', deliveryPayload($this->scenario)))
            ->toThrow(RuntimeException::class);

        expect(Delivery::query()->count())->toBe(0)
            ->and($this->scenario['helmet']->fresh()->status)->toBe(AssetStatus::Available)
            ->and(StockLevel::quantityOf($this->scenario['gloves'], $this->scenario['warehouse']))->toBe(20);
    });
});

describe('FR-006: permisos', function () {
    it('registrar exige entregas.gestionar', function () {
        actingWithPermissions([Permission::EntregasVer->value]);

        $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertForbidden();

        expectNothingDelivered($this->scenario);
    });
});

it('US1-6: las entregas no se editan ni se borran (sin rutas)', function () {
    $delivery = Delivery::factory()->create();

    $this->patchJson("/api/deliveries/{$delivery->id}", ['notes' => 'x'])->assertMethodNotAllowed();
    $this->deleteJson("/api/deliveries/{$delivery->id}")->assertMethodNotAllowed();
});

it('D-003 de 006: sacar existencias de un tipo luego desactivado sí se permite', function () {
    $this->scenario['gloves']->forceFill(['is_active' => false])->save();

    $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertCreated();
});

it('D-002 / decisión 45: la firma se guarda en el disco de firmas (SIGNATURES_DISK), no en el de fotos', function () {
    Storage::fake('firmas');
    config(['filesystems.signatures_disk' => 'firmas']);

    $response = $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertCreated();
    $delivery = Delivery::query()->findOrFail($response->json('data.id'));

    expect(Storage::disk('firmas')->allFiles('signatures'))->toBe([$delivery->signature_path])
        ->and(Storage::disk('local')->allFiles('signatures'))->toBe([])
        ->and($delivery->signatureIntact())->toBeTrue()
        ->and($delivery->signatureUrl())->toContain('signatures');
});

it('B2 (revisión de la migración a B2): si no se puede borrar la firma huérfana, el reporte trae su ruta', function () {
    Exceptions::fake();
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturnFalse();
    $disk->shouldReceive('delete')->once()->andThrow(new RuntimeException('AccessDenied: deleteFiles'));
    Storage::set('firmas_sin_borrado', $disk);
    config(['filesystems.signatures_disk' => 'firmas_sin_borrado']);

    $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertServerError();

    Exceptions::assertReported(function (RuntimeException $exception) {
        return preg_match('#^No se pudo borrar la firma huérfana signatures/\d+\.png #', $exception->getMessage()) === 1
            && $exception->getPrevious()?->getMessage() === 'AccessDenied: deleteFiles';
    });
    expect(Delivery::query()->count())->toBe(0);
});
