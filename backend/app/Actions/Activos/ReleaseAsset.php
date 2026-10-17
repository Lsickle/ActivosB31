<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\Location;
use App\Models\User;
use InvalidArgumentException;

/**
 * Devolución de un activo asignado. La usa Entregas (007, D-001): es la
 * única forma de sacar un activo de Asignado (RN-005).
 *
 * - Estado destino: Disponible, En mantenimiento o Dado de baja (con
 *   motivo; además del evento "returned" registra "retired").
 * - Ubicación destino: la indicada (debe estar disponible) o, si es null,
 *   conserva la actual.
 * - Quita el poseedor y registra "returned" con el empleado que lo tenía.
 *
 * Debe llamarse dentro de la transacción de la devolución.
 */
class ReleaseAsset
{
    use LocksAsset;

    public const string NOT_ASSIGNED_MESSAGE = 'El activo no está asignado.';

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @throws AssetStateException si el activo no está asignado.
     * @throws InvalidArgumentException si el destino no es válido.
     */
    public function handle(
        Asset $asset,
        AssetStatus $target,
        ?Location $location,
        User $user,
        ?string $reason = null,
        ?int $deliveryId = null,
        ?string $notes = null,
    ): Asset {
        if (! in_array($target, AssetStatus::manualTargets(), true)) {
            throw new InvalidArgumentException('El estado destino de una devolución debe ser Disponible, En mantenimiento o Dado de baja.');
        }

        if ($target === AssetStatus::Retired && ($reason === null || trim($reason) === '')) {
            throw new InvalidArgumentException('La baja exige un motivo.');
        }

        if ($location !== null && ! Location::query()->available()->whereKey($location->id)->exists()) {
            throw new InvalidArgumentException('La ubicación seleccionada está inactiva.');
        }

        $this->withLockedAsset($asset, function (Asset $locked) use ($target, $location, $user, $reason, $deliveryId, $notes) {
            if ($locked->status !== AssetStatus::Assigned) {
                throw new AssetStateException(self::NOT_ASSIGNED_MESSAGE);
            }

            $holderId = $locked->holder_employee_id;
            $fromLocationId = $locked->location_id;
            $toLocationId = $location?->id ?? $fromLocationId;

            $locked->forceFill([
                'status' => $target,
                'holder_employee_id' => null,
                'location_id' => $toLocationId,
            ])->save();

            $this->recordEvent->handle($locked, AssetEventType::Returned, $user, [
                'from_status' => AssetStatus::Assigned,
                'to_status' => $target,
                'from_location_id' => $fromLocationId,
                'to_location_id' => $toLocationId,
                'employee_id' => $holderId,
                'delivery_id' => $deliveryId,
                'notes' => $notes,
            ]);

            if ($target === AssetStatus::Retired) {
                $this->recordEvent->handle($locked, AssetEventType::Retired, $user, [
                    'from_status' => AssetStatus::Assigned,
                    'to_status' => AssetStatus::Retired,
                    'employee_id' => $holderId,
                    'delivery_id' => $deliveryId,
                    'notes' => $reason,
                ]);
            }
        });

        return $asset;
    }
}
