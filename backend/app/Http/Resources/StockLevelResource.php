<?php

namespace App\Http\Resources;

use App\Models\StockLevel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de existencias tipo × ubicación (US2, RN-028). type_total y below_min
 * se calculan sobre el total del tipo en todas las ubicaciones (atributo
 * type_total de la consulta).
 *
 * @mixin StockLevel
 */
class StockLevelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $typeTotal = (int) $this->type_total;
        $minStock = $this->assetType->min_stock;

        return [
            'asset_type' => [
                'id' => $this->assetType->id,
                'name' => $this->assetType->name,
                'category' => $this->assetType->category->only(['id', 'name']),
            ],
            'location' => $this->location->only(['id', 'name', 'is_active']),
            'quantity' => $this->quantity,
            'type_total' => $typeTotal,
            'min_stock' => $minStock,
            'below_min' => $minStock !== null && $typeTotal < $minStock,
        ];
    }
}
