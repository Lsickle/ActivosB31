<?php

namespace App\Http\Resources;

use App\Models\AssetType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetType
 */
class AssetTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'tracking_mode' => $this->tracking_mode->value,
            'tracking_mode_label' => $this->tracking_mode->label(),
            // RN-028 / D-008 (005): solo en tipos por cantidad.
            'min_stock' => $this->min_stock,
            'is_active' => $this->is_active,
            // US2-4: se informa el estado de la categoría para señalar los
            // tipos no disponibles por tener la categoría inactiva.
            'category' => $this->whenLoaded('category', fn () => $this->category->only(['id', 'name', 'is_active'])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
