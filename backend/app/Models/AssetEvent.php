<?php

namespace App\Models;

use App\Enums\AssetEventType;
use App\Enums\AssetStatus;
use App\Models\Concerns\InsertOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Evento del historial de un activo (FR-004, US3, D-002). Solo inserción; se
 * crea con App\Actions\Activos\RecordAssetEvent dentro de la transacción de
 * la operación que lo origina.
 */
#[Fillable([
    'asset_id', 'event', 'from_status', 'to_status', 'from_location_id', 'to_location_id',
    'employee_id', 'delivery_id', 'user_id', 'notes', 'changes',
])]
class AssetEvent extends Model
{
    use InsertOnly;

    public const string IMMUTABLE_MESSAGE = 'El historial del activo es de solo inserción.';

    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AssetEventType::class,
            'from_status' => AssetStatus::class,
            'to_status' => AssetStatus::class,
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
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
}
