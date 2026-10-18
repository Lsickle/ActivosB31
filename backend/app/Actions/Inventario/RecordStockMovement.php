<?php

namespace App\Actions\Inventario;

use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Models\AssetType;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * ÚNICA vía para cambiar `stock_levels` (006, D-001, FR-002, SC-001, SC-002):
 * registra el movimiento (solo inserción) y ajusta la existencia en la misma
 * transacción. La usan los movimientos manuales, el cierre de conteos
 * (RN-027) y Entregas (007: delivery, return y loss, RN-038).
 *
 * Ubicaciones por clase (D-002, ver MovementType::acceptsLocations()):
 * - Entry / Return: solo $to (suma).
 * - Exit / Delivery: solo $from (resta).
 * - Transfer: $from y $to distintos (resta y suma).
 * - Adjustment: exactamente uno; $to suma, $from resta.
 * - Loss: ninguna (no toca existencias; lo devuelto dañado o perdido no
 *   vuelve al stock, RN-038).
 * Una combinación inválida es un error de programación: InvalidArgumentException.
 *
 * Reglas de negocio (ValidationException con la clave del campo HTTP):
 * - `quantity`: entero > 0; "Existencia insuficiente." si el origen quedaría
 *   negativo (FR-002).
 * - `asset_type_id`: solo tipos por cantidad (RN-007); en entradas y
 *   traslados, además, tipo activo de categoría activa (D-003).
 * - `to_location_id`: en entradas, traslados y devoluciones (B-5 de 007),
 *   destino activo de tipo de ubicación activo (D-003). Sacar desde una
 *   ubicación inactiva sí se permite (vaciarla).
 * - `reason`: obligatorio en salidas y ajustes (FR-001).
 *
 * Concurrencia (D-001): dentro de la transacción crea las filas que falten
 * con INSERT ... ON CONFLICT DO NOTHING y las bloquea con SELECT ... FOR
 * UPDATE en orden (asset_type_id, location_id); dos salidas simultáneas que
 * juntas superan la existencia no pueden pasar ambas. El CHECK quantity >= 0
 * es la última defensa.
 *
 * Si el llamador registra varios movimientos en una misma transacción (p. ej.
 * una entrega con varios artículos en 007), debe llamarla en orden de
 * (asset_type_id, location_id) para mantener el orden global de bloqueo y
 * evitar interbloqueos. Si alguna llamada lanza excepción, solo se deshace su
 * savepoint: el llamador decide si aborta toda su transacción.
 */
class RecordStockMovement
{
    public const string INSUFFICIENT_STOCK_MESSAGE = 'Existencia insuficiente.';

    /**
     * @throws ValidationException
     * @throws InvalidArgumentException
     */
    public function handle(
        MovementType $type,
        AssetType $assetType,
        int $quantity,
        ?Location $from,
        ?Location $to,
        ?string $reason,
        User $user,
        ?int $deliveryId = null,
        ?int $countId = null,
    ): InventoryMovement {
        $this->ensureLocationsMatch($type, $from, $to);
        $this->validate($type, $assetType, $quantity, $to, $reason);

        return DB::transaction(function () use ($type, $assetType, $quantity, $from, $to, $reason, $user, $deliveryId, $countId) {
            $locationIds = collect([$from?->id, $to?->id])->filter()->unique()->sort()->values()->all();

            if ($locationIds !== []) {
                $levels = $this->lockLevels($assetType->id, $locationIds);

                if ($from !== null && $levels[$from->id] < $quantity) {
                    throw ValidationException::withMessages(['quantity' => [self::INSUFFICIENT_STOCK_MESSAGE]]);
                }
            }

            if ($from !== null) {
                $this->levelQuery($assetType->id, $from->id)->decrement('quantity', $quantity, ['updated_at' => now()]);
            }

            if ($to !== null) {
                $this->levelQuery($assetType->id, $to->id)->increment('quantity', $quantity, ['updated_at' => now()]);
            }

            return InventoryMovement::query()->create([
                'type' => $type,
                'asset_type_id' => $assetType->id,
                'quantity' => $quantity,
                'from_location_id' => $from?->id,
                'to_location_id' => $to?->id,
                'delivery_id' => $deliveryId,
                'count_id' => $countId,
                'reason' => $reason,
                'user_id' => $user->id,
            ]);
        });
    }

    /**
     * Crea las filas que falten (existencia 0) y las bloquea en orden.
     *
     * @param  list<int>  $locationIds  ordenados de menor a mayor
     * @return array<int, int> location_id => existencia
     */
    private function lockLevels(int $assetTypeId, array $locationIds): array
    {
        DB::table('stock_levels')->insertOrIgnore(array_map(fn (int $locationId) => [
            'asset_type_id' => $assetTypeId,
            'location_id' => $locationId,
            'quantity' => 0,
            'updated_at' => now(),
        ], $locationIds));

        return DB::table('stock_levels')
            ->where('asset_type_id', $assetTypeId)
            ->whereIn('location_id', $locationIds)
            ->orderBy('asset_type_id')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->pluck('quantity', 'location_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }

    private function levelQuery(int $assetTypeId, int $locationId): Builder
    {
        return DB::table('stock_levels')->where('asset_type_id', $assetTypeId)->where('location_id', $locationId);
    }

    private function ensureLocationsMatch(MovementType $type, ?Location $from, ?Location $to): void
    {
        if (! $type->acceptsLocations($from !== null, $to !== null)) {
            throw new InvalidArgumentException("Ubicaciones inválidas para un movimiento de tipo {$type->value}.");
        }

        if ($from !== null && $to !== null && $from->is($to)) {
            throw new InvalidArgumentException('El origen y el destino deben ser distintos.');
        }
    }

    /**
     * @throws ValidationException
     */
    private function validate(MovementType $type, AssetType $assetType, int $quantity, ?Location $to, ?string $reason): void
    {
        $errors = [];

        if ($quantity < 1) {
            $errors['quantity'] = 'La cantidad debe ser un número entero mayor que 0.';
        }

        if ($assetType->tracking_mode !== TrackingMode::Quantity) {
            $errors['asset_type_id'] = 'Solo los tipos controlados por cantidad tienen existencias.';
        } elseif ($type->requiresAvailableType() && ! AssetType::query()->available()->whereKey($assetType->id)->exists()) {
            $errors['asset_type_id'] = 'El tipo de activo o su categoría está inactivo.';
        }

        if ($type->requiresAvailableTarget() && $to !== null && ! Location::query()->available()->whereKey($to->id)->exists()) {
            $errors['to_location_id'] = 'La ubicación de destino está inactiva.';
        }

        if ($type->requiresReason() && trim((string) $reason) === '') {
            $errors['reason'] = 'Indica el motivo.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }
    }
}
