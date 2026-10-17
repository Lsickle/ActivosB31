<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Renglón de un conteo (D-004): de cantidad (asset_type_id con esperado y
 * contado) o de activo serializado (asset_id con found; guarda también el
 * tipo del activo). RN-037: un serializado no encontrado solo se reporta.
 */
#[Fillable(['count_id', 'asset_type_id', 'asset_id', 'expected_quantity', 'counted_quantity', 'found'])]
class InventoryCountLine extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_quantity' => 'integer',
            'counted_quantity' => 'integer',
            'found' => 'boolean',
        ];
    }

    public function isSerialized(): bool
    {
        return $this->asset_id !== null;
    }

    /**
     * @return BelongsTo<InventoryCount, $this>
     */
    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'count_id');
    }

    /**
     * @return BelongsTo<AssetType, $this>
     */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
