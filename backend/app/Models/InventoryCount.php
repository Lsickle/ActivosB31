<?php

namespace App\Models;

use App\Enums\CountStatus;
use Database\Factories\InventoryCountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conteo físico de una ubicación (RN-027, D-004). Lo abren y cierran las
 * acciones App\Actions\Inventario\OpenInventoryCount y CloseInventoryCount;
 * un solo conteo abierto por ubicación (índice único parcial). No se borra.
 */
#[Fillable(['location_id', 'notes'])]
class InventoryCount extends Model
{
    /** @use HasFactory<InventoryCountFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * Con microsegundos, igual que InventoryMovement (US3-4).
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'open',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CountStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === CountStatus::Open;
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<InventoryCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InventoryCountLine::class, 'count_id');
    }

    /**
     * Ajustes generados al cerrar (RN-027).
     *
     * @return HasMany<InventoryMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'count_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
