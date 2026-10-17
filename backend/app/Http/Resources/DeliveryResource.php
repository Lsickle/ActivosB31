<?php

namespace App\Http\Resources;

use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\DeliveryReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrega (contrato de specs/007-modulo-entregas/contracts/api.md).
 *
 * - employee: solo id, nombre completo y cargo (RN-022, D-006): sin datos
 *   personales.
 * - status: derivado (D-005).
 * - signature_url: enlace temporal de 10 minutos (D-002), solo en la ficha y
 *   al registrar; no se firma una URL por fila del listado. M-1: solo si el
 *   usuario puede ver la firma (DeliveryPolicy::viewSignature).
 *
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
{
    /**
     * Relaciones que necesita el recurso.
     *
     * @var list<string>
     */
    public const array RELATIONS = [
        'employee:id,first_name,last_name,position_id',
        'employee.position:id,name',
        'fromLocation:id,name',
        'deliveredBy:id,name',
        'items.asset:id,plate',
        'items.assetType:id,name,tracking_mode',
        'items.returns.toLocation:id,name',
        'items.returns.receivedBy:id,name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'employee' => self::employee($this->resource),
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'due_on' => $this->due_on?->toDateString(),
            'status' => $this->status()->value,
            'from_location' => $this->fromLocation->only(['id', 'name']),
            'notes' => $this->notes,
            'delivered_by' => $this->deliveredBy->only(['id', 'name']),
            'delivered_at' => $this->delivered_at,
            // M-1 (RN pendiente: pregunta 33, provisional 33A): solo con
            // DeliveryPolicy::viewSignature.
            'signature_url' => $this->when(
                $request->routeIs('deliveries.show', 'deliveries.store')
                    && $request->user()?->can('viewSignature', $this->resource) === true,
                fn () => $this->signatureUrl(),
            ),
            'items' => $this->items->map(fn (DeliveryItem $item) => [
                'id' => $item->id,
                'asset' => $item->asset?->only(['id', 'plate']),
                'asset_type' => [
                    'id' => $item->assetType->id,
                    'name' => $item->assetType->name,
                    'tracking_mode' => $item->assetType->tracking_mode->value,
                ],
                'quantity' => $item->quantity,
                'returned_quantity' => $item->returned_quantity,
                'pending_quantity' => $item->pending_quantity,
                'returns' => $item->returns->map(fn (DeliveryReturn $return) => [
                    'id' => $return->id,
                    'quantity' => $return->quantity,
                    'condition' => $return->condition->value,
                    'condition_label' => $return->condition->label(),
                    'to_location' => $return->toLocation?->only(['id', 'name']),
                    'notes' => $return->notes,
                    'received_by' => $return->receivedBy->only(['id', 'name']),
                    'returned_at' => $return->returned_at,
                ])->values(),
            ])->values(),
        ];
    }

    /**
     * RN-022 / D-006: el empleado en Entregas, sin datos personales.
     *
     * @return array{id: int, full_name: string, position: string|null}
     */
    public static function employee(Delivery $delivery): array
    {
        return [
            'id' => $delivery->employee->id,
            'full_name' => $delivery->employee->full_name,
            'position' => $delivery->employee->position?->name,
        ];
    }
}
