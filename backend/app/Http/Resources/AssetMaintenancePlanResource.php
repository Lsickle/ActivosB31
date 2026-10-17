<?php

namespace App\Http\Resources;

use App\Models\AssetMaintenancePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Plan de mantenimiento de un activo (RN-029, RN-030, D-003).
 *
 * @mixin AssetMaintenancePlan
 */
class AssetMaintenancePlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'asset_id' => $this->asset_id,
            'name' => $this->name,
            'interval_months' => $this->interval_months,
            'last_done_on' => $this->last_done_on?->toDateString(),
            'next_due_on' => $this->next_due_on->toDateString(),
            'alert_days' => $this->alert_days,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
