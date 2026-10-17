<?php

namespace App\Http\Resources;

use App\Models\InventoryMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Movimiento de inventario (006, FR-001, FR-003). Contrato:
 * specs/006-modulo-inventario/contracts/api.md.
 *
 * @mixin InventoryMovement
 */
class InventoryMovementResource extends JsonResource
{
    /**
     * Relaciones que necesita el recurso.
     *
     * @var list<string>
     */
    public const array RELATIONS = [
        'assetType:id,name',
        'fromLocation:id,name',
        'toLocation:id,name',
        'user:id,name',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'asset_type' => $this->assetType->only(['id', 'name']),
            'quantity' => $this->quantity,
            'from_location' => $this->fromLocation?->only(['id', 'name']),
            'to_location' => $this->toLocation?->only(['id', 'name']),
            'reason' => $this->reason,
            'delivery_id' => $this->delivery_id,
            'count_id' => $this->count_id,
            'user' => $this->user?->only(['id', 'name']),
            'created_at' => $this->created_at,
        ];
    }
}
