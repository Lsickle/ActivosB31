<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Existencia de un tipo por cantidad en una ubicación (006, FR-002). Clave
 * compuesta (asset_type_id, location_id): el modelo es de solo lectura en la
 * práctica; las filas solo las crea y modifica
 * App\Actions\Inventario\RecordStockMovement (D-001) para que la suma de
 * movimientos coincida siempre con la existencia (SC-002).
 */
class StockLevel extends Model
{
    public const ?string CREATED_AT = null;

    /**
     * @var string|null
     */
    protected $primaryKey = null;

    public $incrementing = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Existencia actual (0 si no hay fila).
     */
    public static function quantityOf(AssetType|int $assetType, Location|int $location): int
    {
        return (int) static::query()
            ->where('asset_type_id', $assetType instanceof AssetType ? $assetType->id : $assetType)
            ->where('location_id', $location instanceof Location ? $location->id : $location)
            ->value('quantity');
    }

    /**
     * @return BelongsTo<AssetType, $this>
     */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
