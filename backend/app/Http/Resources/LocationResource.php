<?php

namespace App\Http\Resources;

use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Location
 */
class LocationResource extends JsonResource
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
            'is_active' => $this->is_active,
            // FR-008: se informa el estado del tipo para señalar las
            // ubicaciones no disponibles por tener el tipo inactivo.
            'location_type' => $this->whenLoaded('locationType', fn () => $this->locationType->only(['id', 'name', 'is_active'])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
