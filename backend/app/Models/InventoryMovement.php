<?php

namespace App\Models;

use App\Enums\MovementType;
use App\Models\Concerns\InsertOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Movimiento de inventario (006, FR-001, D-002). Solo inserción (trait +
 * trigger prevent_row_changes()); se crea únicamente con
 * App\Actions\Inventario\RecordStockMovement, que actualiza la existencia en
 * la misma transacción. Un error se corrige con un movimiento inverso.
 */
#[Fillable([
    'type', 'asset_type_id', 'quantity', 'from_location_id', 'to_location_id',
    'delivery_id', 'count_id', 'reason', 'user_id',
])]
class InventoryMovement extends Model
{
    use InsertOnly;

    public const string IMMUTABLE_MESSAGE = 'Los movimientos de inventario son de solo inserción.';

    public const ?string UPDATED_AT = null;

    /**
     * Con microsegundos: al cerrar un conteo se distinguen los movimientos
     * posteriores a la apertura aunque ocurran en el mismo segundo (US3-4).
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => MovementType::class,
            'quantity' => 'integer',
            'created_at' => 'datetime',
        ];
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
    public function fromLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /**
     * Conteo cuyo cierre generó el ajuste (RN-027).
     *
     * @return BelongsTo<InventoryCount, $this>
     */
    public function inventoryCount(): BelongsTo
    {
        return $this->belongsTo(InventoryCount::class, 'count_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
