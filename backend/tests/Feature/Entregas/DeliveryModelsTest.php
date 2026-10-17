<?php

use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Enums\ReturnCondition;
use App\Models\Asset;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryReturn;
use App\Models\Employee;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * T002 — Modelos, enums y factories de Entregas (RN-023, RN-025, RN-026,
 * D-001, D-005).
 */
it('RN-023 / RN-026 / D-005: enums con sus etiquetas', function () {
    expect(DeliveryKind::Dotacion->value)->toBe('dotacion')
        ->and(DeliveryKind::Dotacion->label())->toBe('Dotación')
        ->and(DeliveryKind::Prestamo->label())->toBe('Préstamo')
        ->and(ReturnCondition::Good->label())->toBe('Bueno')
        ->and(ReturnCondition::Damaged->label())->toBe('Dañado')
        ->and(ReturnCondition::Lost->label())->toBe('Perdido')
        ->and(array_column(DeliveryStatus::cases(), 'value'))->toBe(['open', 'closed', 'overdue']);
});

it('D-001: la factory crea una entrega con su consecutivo y relaciones', function () {
    $delivery = Delivery::factory()->create();
    $item = DeliveryItem::factory()->for($delivery)->create(['quantity' => 3]);

    expect($delivery->code)->toMatch('/^ENT-\d{6}$/')
        ->and($delivery->kind)->toBe(DeliveryKind::Dotacion)
        ->and($delivery->due_on)->toBeNull()
        ->and($delivery->employee)->toBeInstanceOf(Employee::class)
        ->and($delivery->fromLocation)->toBeInstanceOf(Location::class)
        ->and($delivery->deliveredBy)->toBeInstanceOf(User::class)
        ->and($delivery->items->sole()->is($item))->toBeTrue()
        ->and($item->pending_quantity)->toBe(3)
        ->and($item->asset)->toBeNull();
});

it('RN-025: un ítem serializado apunta a su activo y tipo', function () {
    $asset = Asset::factory()->create();
    $item = DeliveryItem::factory()->forAsset($asset)->create();

    expect($item->asset->is($asset))->toBeTrue()
        ->and($item->asset_type_id)->toBe($asset->asset_type_id)
        ->and($item->quantity)->toBe(1);
});

it('FR-001 / US1-6: entregas y devoluciones no se modifican ni se borran desde el modelo', function () {
    $delivery = Delivery::factory()->create();
    $item = DeliveryItem::factory()->for($delivery)->create();
    $return = DeliveryReturn::query()->create([
        'delivery_item_id' => $item->id, 'quantity' => 1, 'condition' => ReturnCondition::Good,
        'to_location_id' => Location::factory()->create()->id, 'received_by' => User::factory()->create()->id, 'returned_at' => now(),
    ]);

    expect(fn () => $delivery->forceFill(['notes' => 'x'])->save())->toThrow(LogicException::class, Delivery::IMMUTABLE_MESSAGE)
        ->and(fn () => $delivery->delete())->toThrow(LogicException::class)
        ->and(fn () => $return->forceFill(['notes' => 'x'])->save())->toThrow(LogicException::class, DeliveryReturn::IMMUTABLE_MESSAGE)
        ->and($return->condition)->toBe(ReturnCondition::Good)
        ->and($item->returns()->sole()->is($return))->toBeTrue();
});

describe('D-005: estado derivado (America/Bogota)', function () {
    beforeEach(function () {
        // 2026-10-20 03:00 UTC = 2026-10-19 22:00 en Bogotá.
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00:00', 'UTC'));

        $this->closed = Delivery::factory()->create();
        DeliveryItem::factory()->for($this->closed)->create(['quantity' => 2, 'returned_quantity' => 2]);

        $this->open = Delivery::factory()->create();
        DeliveryItem::factory()->for($this->open)->create(['quantity' => 2, 'returned_quantity' => 1]);

        $this->loanDueToday = Delivery::factory()->loan('2026-10-19')->create();
        DeliveryItem::factory()->for($this->loanDueToday)->create();

        $this->overdue = Delivery::factory()->loan('2026-10-18')->create();
        DeliveryItem::factory()->for($this->overdue)->create(['quantity' => 3, 'returned_quantity' => 1]);

        $this->overdueButClosed = Delivery::factory()->loan('2026-10-01')->create();
        DeliveryItem::factory()->for($this->overdueButClosed)->create(['quantity' => 1, 'returned_quantity' => 1]);
    });

    afterEach(fn () => Carbon::setTestNow());

    it('closed sin pendientes; overdue préstamo con pendientes y fecha pasada; si no, open', function () {
        expect(Delivery::today())->toBe('2026-10-19')
            ->and($this->closed->load('items')->status())->toBe(DeliveryStatus::Closed)
            ->and($this->open->load('items')->status())->toBe(DeliveryStatus::Open)
            ->and($this->loanDueToday->load('items')->status())->toBe(DeliveryStatus::Open)
            ->and($this->overdue->load('items')->status())->toBe(DeliveryStatus::Overdue)
            ->and($this->overdueButClosed->load('items')->status())->toBe(DeliveryStatus::Closed)
            ->and($this->overdue->pendingQuantity())->toBe(2);
    });

    it('el filtro por estado en la consulta coincide con el estado derivado', function () {
        $ids = fn (DeliveryStatus $status) => Delivery::query()->withStatus($status)->orderBy('id')->pluck('id')->all();

        expect($ids(DeliveryStatus::Closed))->toBe([$this->closed->id, $this->overdueButClosed->id])
            ->and($ids(DeliveryStatus::Open))->toBe([$this->open->id, $this->loanDueToday->id])
            ->and($ids(DeliveryStatus::Overdue))->toBe([$this->overdue->id]);
    });
});
