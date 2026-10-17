<?php

namespace App\Models;

use App\Enums\ReturnCondition;
use App\Models\Concerns\InsertOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Devolución de (parte de) un ítem entregado (FR-003, RN-026, D-004). Solo
 * inserción (trait + trigger); se crea con
 * App\Actions\Entregas\RegisterDeliveryReturn.
 */
#[Fillable([
    'delivery_item_id', 'quantity', 'condition', 'to_location_id', 'notes', 'received_by', 'returned_at',
])]
class DeliveryReturn extends Model
{
    use InsertOnly;

    public const string IMMUTABLE_MESSAGE = 'Las devoluciones no se modifican ni se borran.';

    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'condition' => ReturnCondition::class,
            'returned_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DeliveryItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(DeliveryItem::class, 'delivery_item_id');
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function toLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
