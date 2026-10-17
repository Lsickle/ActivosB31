<?php

namespace App\Http\Resources;

use App\Models\LocationType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LocationType
 */
class LocationTypeResource extends JsonResource
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
            'locations_count' => $this->whenCounted('locations'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
