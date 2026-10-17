<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\User;

/**
 * Entrega de un activo serializado a un empleado. La usa Entregas (007,
 * D-001): es la única forma de poner un activo en Asignado (RN-005).
 *
 * - Exige que el activo esté Disponible (leído con bloqueo de fila).
 * - RN-039: fija quién lo tiene y conserva su última ubicación.
 * - Registra el evento "delivered" con el empleado y, si se indica, la
 *   entrega (delivery_id).
 * - Un empleado inactivo no recibe entregas (US1-4 de 004).
 *
 * Debe llamarse dentro de la transacción de la entrega para que todo se
 * confirme o se deshaga junto (las transacciones se anidan).
 */
class AssignAsset
{
    use LocksAsset;

    public const string NOT_AVAILABLE_MESSAGE = 'Solo se pueden entregar activos disponibles.';

    public const string INACTIVE_EMPLOYEE_MESSAGE = 'El empleado está inactivo.';

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @throws AssetStateException
     */
    public function handle(Asset $asset, Employee $employee, User $user, ?int $deliveryId = null, ?string $notes = null): Asset
    {
        if (! $employee->is_active) {
            throw new AssetStateException(self::INACTIVE_EMPLOYEE_MESSAGE);
        }

        $this->withLockedAsset($asset, function (Asset $locked) use ($employee, $user, $deliveryId, $notes) {
            if ($locked->status !== AssetStatus::Available) {
                throw new AssetStateException(self::NOT_AVAILABLE_MESSAGE);
            }

            $locked->forceFill([
                'status' => AssetStatus::Assigned,
                'holder_employee_id' => $employee->id,
            ])->save();

            $this->recordEvent->handle($locked, AssetEventType::Delivered, $user, [
                'from_status' => AssetStatus::Available,
                'to_status' => AssetStatus::Assigned,
                'employee_id' => $employee->id,
                'delivery_id' => $deliveryId,
                'notes' => $notes,
            ]);
        });

        return $asset;
    }
}
