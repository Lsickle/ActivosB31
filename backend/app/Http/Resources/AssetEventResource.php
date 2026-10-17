<?php

namespace App\Http\Resources;

use App\Models\AssetEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento del historial del activo (FR-004, US3, D-002). El empleado solo con
 * id y nombre completo (RN-022).
 *
 * @mixin AssetEvent
 */
class AssetEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'from_status' => $this->from_status?->value,
            'to_status' => $this->to_status?->value,
            'from_location' => $this->fromLocation?->only(['id', 'name']),
            'to_location' => $this->toLocation?->only(['id', 'name']),
            'employee' => $this->employee === null ? null : ['id' => $this->employee->id, 'full_name' => $this->employee->full_name],
            'delivery_id' => $this->delivery_id,
            'user' => $this->user?->only(['id', 'name']),
            'notes' => $this->notes,
            'changes' => $this->changes,
            'created_at' => $this->created_at,
        ];
    }
}
