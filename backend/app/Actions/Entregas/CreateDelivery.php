<?php

namespace App\Actions\Entregas;

use App\Actions\Activos\AssignAsset;
use App\Actions\Inventario\RecordStockMovement;
use App\Enums\AssetStatus;
use App\Enums\DeliveryKind;
use App\Enums\MovementType;
use App\Enums\TrackingMode;
use App\Exceptions\AssetStateException;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Employee;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * Registra una entrega firmada (007, FR-001, FR-002, D-003). Todo o nada:
 *
 * 1. Validación previa (todos los errores a la vez, con la clave del
 *    artículo items.N.*): empleado activo, activos Disponibles, tipos por
 *    cantidad y existencia suficiente en la ubicación de origen.
 * 2. En una transacción: entrega (id reservado de la secuencia para fijar
 *    signatures/{id}.png sin actualizar la fila inmutable), AssignAsset por
 *    activo (bloqueo de fila; RN-039, evento "delivered"), ítems y
 *    RecordStockMovement(delivery) por tipo. Lo que la validación previa no
 *    pudo ver (otra entrega simultánea del mismo activo o de la misma
 *    existencia) lo detectan esas acciones con la fila bloqueada y se
 *    informa igual en items.N.*.
 * 3. La firma se escribe al final de la transacción; si algo falla (también
 *    al confirmar) se borra el archivo: no quedan firmas huérfanas. Su
 *    SHA-256 queda en signature_sha256 (M-3).
 *
 * Orden de bloqueo (evita interbloqueos entre entregas y devoluciones):
 * activos por id y luego existencias por (asset_type_id, location_id), como
 * exige RecordStockMovement.
 */
class CreateDelivery
{
    public const string NOT_QUANTITY_TYPE_MESSAGE = 'Selecciona un tipo de artículo controlado por cantidad (los serializados se entregan por placa).';

    public function __construct(
        private AssignAsset $assignAsset,
        private RecordStockMovement $recordMovement,
    ) {}

    /**
     * @param  array<int, array{asset_id?: int|null, asset_type_id?: int|null, quantity?: int|null}>  $items
     *
     * @throws ValidationException
     */
    public function handle(
        Employee $employee,
        DeliveryKind $kind,
        ?string $dueOn,
        Location $from,
        ?string $notes,
        string $signaturePng,
        array $items,
        User $user,
    ): Delivery {
        [$assets, $types] = $this->ensureDeliverable($employee, $from, $items);

        $disk = Delivery::signatureDisk();
        $signaturePath = null;

        try {
            return DB::transaction(function () use ($employee, $kind, $dueOn, $from, $notes, $signaturePng, $items, $user, $assets, $types, $disk, &$signaturePath) {
                $id = (int) DB::scalar("select nextval(pg_get_serial_sequence('deliveries', 'id'))");

                // B-1: id, ruta y huella de la firma no son asignables en
                // masa; solo esta acción los fija. M-3: la huella SHA-256 del
                // PNG se calcula antes de insertar (la fila es inmutable).
                $delivery = Delivery::query()->forceCreate([
                    'id' => $id,
                    'employee_id' => $employee->id,
                    'kind' => $kind,
                    'due_on' => $kind === DeliveryKind::Prestamo ? $dueOn : null,
                    'from_location_id' => $from->id,
                    'signature_path' => Delivery::signaturePathFor($id),
                    'signature_sha256' => hash('sha256', $signaturePng),
                    'notes' => $notes,
                    'delivered_by' => $user->id,
                    'delivered_at' => now(),
                ]);

                // Primero los activos (bloqueo de fila): una entrega simultánea
                // del mismo activo espera aquí y luego ve que ya no está
                // Disponible, antes de tocar delivery_items (cuyo índice único
                // de pendientes la haría fallar con un error de BD).
                $this->assignAssets($delivery, $employee, $user, $items, $assets);

                foreach ($items as $item) {
                    $asset = isset($item['asset_id']) ? $assets[$item['asset_id']] : null;

                    DeliveryItem::query()->create([
                        'delivery_id' => $delivery->id,
                        'asset_id' => $asset?->id,
                        'asset_type_id' => $asset?->asset_type_id ?? $item['asset_type_id'],
                        'quantity' => $asset !== null ? 1 : $item['quantity'],
                    ]);
                }

                $this->moveStock($delivery, $from, $user, $items, $types);

                $signaturePath = $delivery->signature_path;

                if (! $disk->put($signaturePath, $signaturePng)) {
                    throw new RuntimeException('No se pudo guardar la firma de la entrega.');
                }

                return $delivery;
            });
        } catch (Throwable $exception) {
            if ($signaturePath !== null) {
                $this->deleteOrphanSignature($disk, $signaturePath);
            }

            throw $exception;
        }
    }

    /**
     * B2 (revisión de seguridad de la migración a B2): en producción la llave
     * de firmas no tiene deleteFiles y el bucket tiene Object Lock, así que
     * borrar la firma de una entrega revertida puede fallar. No interrumpe
     * la respuesta, pero se reporta con la ruta (signatures/<id>.png, sin
     * datos personales) para poder ubicar el archivo huérfano en el bucket.
     */
    private function deleteOrphanSignature(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            report(new RuntimeException(
                "No se pudo borrar la firma huérfana {$path} de una entrega revertida (queda en el disco de firmas).",
                previous: $exception,
            ));
        }
    }

    /**
     * @param  array<int, array{asset_id?: int|null, asset_type_id?: int|null, quantity?: int|null}>  $items
     * @return array{0: Collection<int, Asset>, 1: Collection<int, AssetType>}
     *
     * @throws ValidationException
     */
    private function ensureDeliverable(Employee $employee, Location $from, array $items): array
    {
        $errors = [];

        if (! $employee->is_active) {
            $errors['employee_id'] = AssignAsset::INACTIVE_EMPLOYEE_MESSAGE;
        }

        $assets = Asset::query()->whereIn('id', array_filter(array_column($items, 'asset_id')))->get()->keyBy('id');
        $types = AssetType::query()->whereIn('id', array_filter(array_column($items, 'asset_type_id')))->get()->keyBy('id');

        foreach ($items as $index => $item) {
            if (isset($item['asset_id'])) {
                if ($assets[$item['asset_id']]->status !== AssetStatus::Available) {
                    $errors["items.{$index}.asset_id"] = AssignAsset::NOT_AVAILABLE_MESSAGE;
                }

                continue;
            }

            $type = $types[$item['asset_type_id']];

            if ($type->tracking_mode !== TrackingMode::Quantity) {
                $errors["items.{$index}.asset_type_id"] = self::NOT_QUANTITY_TYPE_MESSAGE;
            } elseif (StockLevel::quantityOf($type, $from) < $item['quantity']) {
                $errors["items.{$index}.quantity"] = RecordStockMovement::INSUFFICIENT_STOCK_MESSAGE;
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(array_map(fn (string $message) => [$message], $errors));
        }

        return [$assets, $types];
    }

    /**
     * @param  array<int, array{asset_id?: int|null}>  $items
     * @param  Collection<int, Asset>  $assets
     *
     * @throws ValidationException
     */
    private function assignAssets(Delivery $delivery, Employee $employee, User $user, array $items, Collection $assets): void
    {
        $indexes = collect($items)->filter(fn (array $item) => isset($item['asset_id']))->sortBy('asset_id');

        foreach ($indexes as $index => $item) {
            try {
                $this->assignAsset->handle($assets[$item['asset_id']], $employee, $user, $delivery->id);
            } catch (AssetStateException $exception) {
                $key = $exception->getMessage() === AssignAsset::INACTIVE_EMPLOYEE_MESSAGE ? 'employee_id' : "items.{$index}.asset_id";

                throw ValidationException::withMessages([$key => [$exception->getMessage()]]);
            }
        }
    }

    /**
     * @param  array<int, array{asset_id?: int|null, asset_type_id?: int|null, quantity?: int|null}>  $items
     * @param  Collection<int, AssetType>  $types
     *
     * @throws ValidationException
     */
    private function moveStock(Delivery $delivery, Location $from, User $user, array $items, Collection $types): void
    {
        $indexes = collect($items)->filter(fn (array $item) => ! isset($item['asset_id']))->sortBy('asset_type_id');

        foreach ($indexes as $index => $item) {
            try {
                $this->recordMovement->handle(
                    MovementType::Delivery, $types[$item['asset_type_id']], (int) $item['quantity'], $from, null, null, $user, $delivery->id,
                );
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field) => [
                        "items.{$index}.".($field === 'asset_type_id' ? 'asset_type_id' : 'quantity') => $messages,
                    ])
                    ->all());
            }
        }
    }
}
