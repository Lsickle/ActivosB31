<?php

namespace App\Http\Resources;

use App\Models\AssetMaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Mantenimiento realizado (FR-005).
 *
 * @mixin AssetMaintenanceRecord
 */
class AssetMaintenanceRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan' => $this->plan?->only(['id', 'name']),
            'performed_on' => $this->performed_on->toDateString(),
            'description' => $this->description,
            'performed_by' => $this->performed_by,
            'cost' => $this->cost,
            'user' => $this->user?->only(['id', 'name']),
            'created_at' => $this->created_at,
        ];
    }
}
