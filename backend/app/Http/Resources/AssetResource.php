<?php

namespace App\Http\Resources;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Activo serializado (contrato de specs/005-modulo-activos/plan.md).
 *
 * - photo_url: enlace temporal de 10 minutos (FR-008); en el listado va en
 *   null para no firmar una URL por fila (se pide en la ficha).
 * - holder: solo id, nombre completo y cargo, como /api/employees/options
 *   (RN-022, D-004 de 004): sin datos personales.
 * - asset_type.is_active / category.is_active: edge case de 005, el activo
 *   conserva un tipo luego desactivado y se muestra con la indicación.
 *
 * @mixin Asset
 */
class AssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plate' => $this->plate,
            'serial' => $this->serial,
            'brand' => $this->brand,
            'model' => $this->model,
            'acquisition_date' => $this->acquisition_date?->toDateString(),
            'acquisition_value' => $this->acquisition_value,
            'useful_life_months' => $this->useful_life_months,
            'expires_on' => $this->expires_on?->toDateString(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'notes' => $this->notes,
            'photo_url' => $request->routeIs('assets.index') ? null : $this->photoUrl(),
            'asset_type' => $this->whenLoaded('assetType', fn () => [
                'id' => $this->assetType->id,
                'name' => $this->assetType->name,
                'tracking_mode' => $this->assetType->tracking_mode->value,
                'is_active' => $this->assetType->is_active,
                'category' => $this->assetType->category->only(['id', 'name', 'is_active']),
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location?->only(['id', 'name', 'is_active'])),
            'holder' => $this->whenLoaded('holder', fn () => $this->holder === null ? null : [
                'id' => $this->holder->id,
                'full_name' => $this->holder->full_name,
                'position' => $this->holder->position?->name,
            ]),
            'next_maintenance_on' => $this->next_maintenance_on,
            'maintenance_plans' => AssetMaintenancePlanResource::collection($this->whenLoaded('maintenancePlans')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
