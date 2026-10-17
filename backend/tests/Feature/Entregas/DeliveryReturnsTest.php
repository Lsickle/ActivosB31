<?php

use App\Actions\Entregas\CreateDelivery;
use App\Actions\Entregas\RegisterDeliveryReturn;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Enums\DeliveryKind;
use App\Enums\MovementType;
use App\Enums\Permission;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryReturn;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\StockLevel;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * T005 — POST /api/deliveries/{id}/returns (FR-003, D-004; RN-026, RN-038):
 * devoluciones parciales o totales por artículo con estado bueno, dañado o
 * perdido.
 */
beforeEach(function () {
    Storage::fake('local');

    $this->scenario = deliveryScenario();
    $this->engine = Location::factory()->create(['name' => 'Máquina 1']);
    $this->user = actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);

    $this->delivery = app(CreateDelivery::class)->handle(
        $this->scenario['employee'], DeliveryKind::Dotacion, null, $this->scenario['warehouse'], null, signaturePng(),
        [['asset_id' => $this->scenario['helmet']->id], ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 2]],
        $this->user,
    );
    $this->helmetItem = $this->delivery->items()->whereNotNull('asset_id')->sole();
    $this->glovesItem = $this->delivery->items()->whereNull('asset_id')->sole();
});

function returnUrl(Delivery $delivery): string
{
    return "/api/deliveries/{$delivery->id}/returns";
}

describe('serializados (RN-026)', function () {
    it('US2-1: devuelto bueno queda Disponible en la ubicación elegida y sin empleado', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id, 'notes' => 'Sin novedad'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.items.0.returned_quantity', 1)
            ->assertJsonPath('data.items.0.pending_quantity', 0)
            ->assertJsonPath('data.items.0.returns.0.quantity', 1)
            ->assertJsonPath('data.items.0.returns.0.condition', 'good')
            ->assertJsonPath('data.items.0.returns.0.condition_label', 'Bueno')
            ->assertJsonPath('data.items.0.returns.0.to_location', ['id' => $this->engine->id, 'name' => 'Máquina 1'])
            ->assertJsonPath('data.items.0.returns.0.notes', 'Sin novedad')
            ->assertJsonPath('data.items.0.returns.0.received_by', ['id' => $this->user->id, 'name' => $this->user->name])
            ->assertJsonPath('data.status', 'open')
            ->assertJsonMissingPath('data.signature_url');

        $helmet = $this->scenario['helmet']->fresh();
        $event = $helmet->events()->where('event', AssetEventType::Returned)->sole();

        expect($helmet->status)->toBe(AssetStatus::Available)
            ->and($helmet->holder_employee_id)->toBeNull()
            ->and($helmet->location_id)->toBe($this->engine->id)
            ->and($event->delivery_id)->toBe($this->delivery->id)
            ->and($event->employee_id)->toBe($this->scenario['employee']->id)
            ->and($event->notes)->toBe('Sin novedad');
    });

    it('US2-2: devuelto dañado queda En mantenimiento en la ubicación elegida', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'damaged', 'to_location_id' => $this->engine->id],
        ]])->assertOk()->assertJsonPath('data.items.0.returns.0.condition_label', 'Dañado');

        $helmet = $this->scenario['helmet']->fresh();

        expect($helmet->status)->toBe(AssetStatus::Maintenance)
            ->and($helmet->location_id)->toBe($this->engine->id)
            ->and($helmet->holder_employee_id)->toBeNull();
    });

    it('US2-3: reportado perdido queda Dado de baja con la observación como motivo', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'lost', 'to_location_id' => $this->engine->id, 'notes' => 'Se perdió en el incendio'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.items.0.returns.0.condition_label', 'Perdido')
            ->assertJsonPath('data.items.0.returns.0.to_location', null);

        $helmet = $this->scenario['helmet']->fresh();
        $retired = $helmet->events()->where('event', AssetEventType::Retired)->sole();

        expect($helmet->status)->toBe(AssetStatus::Retired)
            ->and($helmet->location_id)->toBe($this->scenario['warehouse']->id)
            ->and($retired->notes)->toBe('Se perdió en el incendio')
            ->and($retired->delivery_id)->toBe($this->delivery->id);
    });

    it('lo perdido exige observación', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'lost'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.notes']);

        expect($this->scenario['helmet']->fresh()->status)->toBe(AssetStatus::Assigned);
    });

    it('bueno y dañado exigen la ubicación a la que vuelve; debe estar disponible', function (string $condition) {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => $condition],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.to_location_id']);

        $inactive = Location::factory()->inactive()->create();

        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => $condition, 'to_location_id' => $inactive->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.to_location_id' => RegisterDeliveryReturn::INACTIVE_LOCATION_MESSAGE]);

        expect($this->scenario['helmet']->fresh()->status)->toBe(AssetStatus::Assigned);
    })->with(['good', 'damaged']);

    it('un serializado se devuelve de a 1', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 2, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);
    });
});

describe('por cantidad (RN-038)', function () {
    it('US2-4: devuelto 1 bueno vuelve a la ubicación elegida y queda 1 pendiente', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ]])
            ->assertOk()
            ->assertJsonPath('data.items.1.returned_quantity', 1)
            ->assertJsonPath('data.items.1.pending_quantity', 1)
            ->assertJsonPath('data.status', 'open');

        $movement = InventoryMovement::query()->where('type', MovementType::Return)->sole();

        expect(StockLevel::quantityOf($this->scenario['gloves'], $this->engine))->toBe(1)
            ->and(StockLevel::quantityOf($this->scenario['gloves'], $this->scenario['warehouse']))->toBe(18)
            ->and($movement->delivery_id)->toBe($this->delivery->id)
            ->and($movement->to_location_id)->toBe($this->engine->id)
            ->and($movement->quantity)->toBe(1);
    });

    it('US2-5: dañados o perdidos no vuelven al stock y quedan como pérdida sin ubicación', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 1, 'condition' => 'damaged', 'to_location_id' => $this->engine->id],
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 1, 'condition' => 'lost', 'notes' => 'Quemados'],
        ]])
            ->assertOk()
            ->assertJsonPath('data.items.1.pending_quantity', 0)
            ->assertJsonPath('data.items.1.returns.0.to_location', null)
            ->assertJsonPath('data.items.1.returns.1.to_location', null);

        $losses = InventoryMovement::query()->where('type', MovementType::Loss)->orderBy('id')->get();

        expect($losses)->toHaveCount(2)
            ->and($losses->pluck('delivery_id')->unique()->all())->toBe([$this->delivery->id])
            ->and($losses->pluck('from_location_id')->filter()->all())->toBe([])
            ->and($losses->pluck('to_location_id')->filter()->all())->toBe([])
            ->and($losses[0]->reason)->toContain($this->delivery->code)->toContain('Dañado')
            ->and($losses[1]->reason)->toContain('Perdido')->toContain('Quemados')
            ->and(StockLevel::quantityOf($this->scenario['gloves'], $this->engine))->toBe(0)
            ->and(StockLevel::quantityOf($this->scenario['gloves'], $this->scenario['warehouse']))->toBe(18);
    });

    it('bueno exige la ubicación de destino', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 1, 'condition' => 'good'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.to_location_id']);
    });
});

describe('cantidades y pertenencia', function () {
    it('no se devuelve más de lo pendiente, tampoco sumando renglones del mismo artículo', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 3, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 2, 'condition' => 'good', 'to_location_id' => $this->engine->id],
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 1, 'condition' => 'lost', 'notes' => 'x'],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.1.quantity'])->assertJsonMissingValidationErrors(['items.0.quantity']);

        expect($this->glovesItem->fresh()->returned_quantity)->toBe(0);
    });

    it('lo ya devuelto no se vuelve a devolver', function () {
        $payload = ['items' => [['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id]]];

        $this->postJson(returnUrl($this->delivery), $payload)->assertOk();
        $this->postJson(returnUrl($this->delivery), $payload)->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity']);

        expect(DeliveryReturn::query()->count())->toBe(1);
    });

    it('el artículo debe pertenecer a la entrega', function () {
        $other = DeliveryItem::factory()->create(['quantity' => 3]);

        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $other->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.delivery_item_id']);
    });

    it('valida los campos de cada renglón', function () {
        $this->postJson(returnUrl($this->delivery), ['items' => []])->assertUnprocessable()->assertJsonValidationErrors(['items']);

        $this->postJson(returnUrl($this->delivery), ['items' => [
            ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 0, 'condition' => 'roto', 'notes' => str_repeat('a', 1001)],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['items.0.quantity', 'items.0.condition', 'items.0.notes']);
    });
});

it('D-004: todo o nada: un renglón inválido no aplica los demás', function () {
    $this->postJson(returnUrl($this->delivery), ['items' => [
        ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 5, 'condition' => 'good', 'to_location_id' => $this->engine->id],
    ]])->assertUnprocessable()->assertJsonValidationErrors(['items.1.quantity']);

    expect($this->scenario['helmet']->fresh()->status)->toBe(AssetStatus::Assigned)
        ->and($this->helmetItem->fresh()->returned_quantity)->toBe(0)
        ->and(DeliveryReturn::query()->count())->toBe(0)
        ->and(InventoryMovement::query()->where('type', MovementType::Return)->count())->toBe(0);
});

it('US2-6: devuelto todo, la entrega queda Cerrada', function () {
    $this->postJson(returnUrl($this->delivery), ['items' => [
        ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
        ['delivery_item_id' => $this->glovesItem->id, 'quantity' => 2, 'condition' => 'good', 'to_location_id' => $this->scenario['warehouse']->id],
    ]])->assertOk()->assertJsonPath('data.status', 'closed');

    expect(StockLevel::quantityOf($this->scenario['gloves'], $this->scenario['warehouse']))->toBe(20);

    $this->getJson("/api/deliveries/{$this->delivery->id}")->assertOk()->assertJsonPath('data.status', 'closed');
});

it('edge case: un empleado desactivado puede devolver lo que tiene', function () {
    $this->scenario['employee']->forceFill(['is_active' => false])->save();

    $this->postJson(returnUrl($this->delivery), ['items' => [
        ['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id],
    ]])->assertOk();
});

it('FR-006: registrar devoluciones exige entregas.gestionar; entrega inexistente 404', function () {
    $payload = ['items' => [['delivery_item_id' => $this->helmetItem->id, 'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id]]];

    $this->postJson('/api/deliveries/999999/returns', $payload)->assertNotFound();

    actingWithPermissions([Permission::EntregasVer->value]);

    $this->postJson(returnUrl($this->delivery), $payload)->assertForbidden();

    expect($this->scenario['helmet']->fresh()->status)->toBe(AssetStatus::Assigned);
});
