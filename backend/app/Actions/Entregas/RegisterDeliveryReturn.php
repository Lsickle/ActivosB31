<?php

namespace App\Actions\Entregas;

use App\Actions\Activos\ReleaseAsset;
use App\Actions\Inventario\RecordStockMovement;
use App\Enums\AssetStatus;
use App\Enums\MovementType;
use App\Enums\ReturnCondition;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryReturn;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Registra devoluciones parciales o totales de una entrega (FR-003, D-004,
 * RN-026, RN-038). Todo o nada, en una transacción:
 *
 * 1. Bloquea los ítems de la entrega (SELECT ... FOR UPDATE, por id) y valida
 *    cada renglón con la clave items.N.*: el ítem es de esta entrega, la
 *    cantidad no supera lo pendiente (sumando renglones del mismo ítem), el
 *    serializado se devuelve de a 1 y la ubicación, cuando corresponde, es
 *    obligatoria y está disponible.
 * 2. Registra las devoluciones (solo inserción) y acumula returned_quantity.
 * 3. Serializados (ReleaseAsset, por id de activo): bueno → Disponible en la
 *    ubicación; dañado → En mantenimiento en la ubicación; perdido → Dado de
 *    baja con la observación como motivo (conserva su última ubicación).
 * 4. Por cantidad (RecordStockMovement, en orden (asset_type_id,
 *    location_id)): bueno → devolución a la ubicación; dañado o perdido →
 *    pérdida sin ubicación (no vuelven al stock, RN-038). B-5: si la
 *    ubicación se desactivó en carrera, RecordStockMovement la rechaza y el
 *    error se devuelve en items.N.to_location_id (se deshace todo).
 *
 * Orden de bloqueo: ítems → activos → existencias (el mismo de CreateDelivery
 * para activos y existencias).
 */
class RegisterDeliveryReturn
{
    public const string NOT_IN_DELIVERY_MESSAGE = 'El artículo no pertenece a esta entrega.';

    public const string INACTIVE_LOCATION_MESSAGE = 'La ubicación seleccionada está inactiva.';

    public const string LOCATION_REQUIRED_MESSAGE = 'Selecciona la ubicación a la que vuelve.';

    public function __construct(
        private ReleaseAsset $releaseAsset,
        private RecordStockMovement $recordMovement,
    ) {}

    /**
     * @param  array<int, array{delivery_item_id: int, quantity: int, condition: string, to_location_id?: int|null, notes?: string|null}>  $rows
     *
     * @throws ValidationException
     */
    public function handle(Delivery $delivery, array $rows, User $user): Delivery
    {
        DB::transaction(function () use ($delivery, $rows, $user) {
            $items = DeliveryItem::query()
                ->where('delivery_id', $delivery->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $returns = $this->validateRows($rows, $items);
            $returnedAt = now();

            foreach ($returns as $return) {
                DeliveryReturn::query()->create([
                    'delivery_item_id' => $return['item']->id,
                    'quantity' => $return['quantity'],
                    'condition' => $return['condition'],
                    'to_location_id' => $return['location']?->id,
                    'notes' => $return['notes'],
                    'received_by' => $user->id,
                    'returned_at' => $returnedAt,
                ]);

                $return['item']->increment('returned_quantity', $return['quantity']);
            }

            $this->releaseAssets($delivery, $returns, $user);
            $this->moveStock($delivery, $returns, $user);
        });

        return $delivery;
    }

    /**
     * @param  array<int, array{delivery_item_id: int, quantity: int, condition: string, to_location_id?: int|null, notes?: string|null}>  $rows
     * @param  Collection<int, DeliveryItem>  $items
     * @return Collection<int, array{item: DeliveryItem, quantity: int, condition: ReturnCondition, location: Location|null, notes: string|null}>
     *
     * @throws ValidationException
     */
    private function validateRows(array $rows, Collection $items): Collection
    {
        $errors = [];
        $remaining = $items->map(fn (DeliveryItem $item) => $item->pending_quantity);
        $locations = Location::query()
            ->available()
            ->whereIn('locations.id', array_filter(array_column($rows, 'to_location_id')))
            ->get()
            ->keyBy('id');
        $returns = collect();

        foreach ($rows as $index => $row) {
            $item = $items->get($row['delivery_item_id']);

            if ($item === null) {
                $errors["items.{$index}.delivery_item_id"] = self::NOT_IN_DELIVERY_MESSAGE;

                continue;
            }

            $condition = ReturnCondition::from($row['condition']);
            $quantity = (int) $row['quantity'];

            if ($quantity > $remaining[$item->id]) {
                $errors["items.{$index}.quantity"] = $remaining[$item->id] === 0
                    ? 'El artículo ya fue devuelto.'
                    : "La cantidad supera lo pendiente ({$remaining[$item->id]}).";
            } else {
                $remaining[$item->id] -= $quantity;
            }

            $location = null;

            if ($condition->returnsToLocation($item->isSerialized())) {
                $locationId = $row['to_location_id'] ?? null;
                $location = $locationId === null ? null : $locations->get($locationId);

                if ($locationId === null) {
                    $errors["items.{$index}.to_location_id"] = self::LOCATION_REQUIRED_MESSAGE;
                } elseif ($location === null) {
                    $errors["items.{$index}.to_location_id"] = self::INACTIVE_LOCATION_MESSAGE;
                }
            }

            $returns->put($index, [
                'item' => $item,
                'quantity' => $quantity,
                'condition' => $condition,
                'location' => $location,
                'notes' => $row['notes'] ?? null,
            ]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }

        return $returns;
    }

    /**
     * @param  Collection<int, array{item: DeliveryItem, quantity: int, condition: ReturnCondition, location: Location|null, notes: string|null}>  $returns
     */
    private function releaseAssets(Delivery $delivery, Collection $returns, User $user): void
    {
        $serialized = $returns
            ->filter(fn (array $return) => $return['item']->isSerialized())
            ->sortBy(fn (array $return) => $return['item']->asset_id);

        foreach ($serialized as $index => $return) {
            $target = match ($return['condition']) {
                ReturnCondition::Good => AssetStatus::Available,
                ReturnCondition::Damaged => AssetStatus::Maintenance,
                ReturnCondition::Lost => AssetStatus::Retired,
            };

            // B-5: la ubicación pudo desactivarse entre la validación previa
            // y el bloqueo del activo; ReleaseAsset lo detecta con una
            // InvalidArgumentException, que se informa en el renglón.
            try {
                $this->releaseAsset->handle(
                    $return['item']->asset,
                    $target,
                    $return['location'],
                    $user,
                    reason: $target === AssetStatus::Retired ? $return['notes'] : null,
                    deliveryId: $delivery->id,
                    notes: $return['notes'],
                );
            } catch (InvalidArgumentException) {
                throw ValidationException::withMessages(["items.{$index}.to_location_id" => [self::INACTIVE_LOCATION_MESSAGE]]);
            }
        }
    }

    /**
     * @param  Collection<int, array{item: DeliveryItem, quantity: int, condition: ReturnCondition, location: Location|null, notes: string|null}>  $returns
     */
    private function moveStock(Delivery $delivery, Collection $returns, User $user): void
    {
        $byQuantity = $returns
            ->reject(fn (array $return) => $return['item']->isSerialized())
            ->sortBy([
                fn (array $a, array $b) => $a['item']->asset_type_id <=> $b['item']->asset_type_id,
                fn (array $a, array $b) => ($a['location']?->id ?? 0) <=> ($b['location']?->id ?? 0),
            ]);

        foreach ($byQuantity as $index => $return) {
            $isGood = $return['condition'] === ReturnCondition::Good;

            // B-5: igual que con los serializados, la ubicación pudo
            // desactivarse después de la validación previa; RecordStockMovement
            // la revisa de nuevo y el error se informa en el renglón.
            try {
                $this->recordMovement->handle(
                    $isGood ? MovementType::Return : MovementType::Loss,
                    $return['item']->assetType,
                    $return['quantity'],
                    null,
                    $isGood ? $return['location'] : null,
                    self::movementReason($delivery, $return['condition'], $return['notes']),
                    $user,
                    $delivery->id,
                );
            } catch (ValidationException $exception) {
                if (! array_key_exists('to_location_id', $exception->errors())) {
                    throw $exception;
                }

                throw ValidationException::withMessages(["items.{$index}.to_location_id" => [self::INACTIVE_LOCATION_MESSAGE]]);
            }
        }
    }

    /**
     * Motivo del movimiento (inventory_movements.reason, máx. 500).
     */
    private static function movementReason(Delivery $delivery, ReturnCondition $condition, ?string $notes): string
    {
        $reason = "Devolución {$delivery->code}: {$condition->label()}";

        if ($notes !== null && trim($notes) !== '') {
            $reason .= ". {$notes}";
        }

        return mb_substr($reason, 0, 500);
    }
}
