<?php

namespace App\Http\Resources;

use App\Models\AssetCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetCategory
 */
class AssetCategoryResource extends JsonResource
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
            'asset_types_count' => $this->whenCounted('assetTypes'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
