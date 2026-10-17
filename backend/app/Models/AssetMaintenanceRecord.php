<?php

namespace App\Models;

use App\Models\Concerns\InsertOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mantenimiento realizado (FR-005): fecha, descripción, quién lo hizo y
 * costo opcional. Solo inserción (SC-003).
 */
#[Fillable(['asset_id', 'plan_id', 'performed_on', 'description', 'performed_by', 'cost', 'user_id'])]
class AssetMaintenanceRecord extends Model
{
    use InsertOnly;

    public const string IMMUTABLE_MESSAGE = 'Los mantenimientos realizados son de solo inserción.';

    public const ?string UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'performed_on' => 'date:Y-m-d',
            'cost' => 'decimal:2',
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
     * @return BelongsTo<AssetMaintenancePlan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(AssetMaintenancePlan::class, 'plan_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
