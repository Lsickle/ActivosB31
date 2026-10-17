<?php

namespace App\Models;

use Database\Factories\DeliveryItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Artículo de una entrega (RN-025): un activo serializado (cantidad 1) o una
 * cantidad de un tipo por cantidad. Solo returned_quantity cambia, con la
 * fila bloqueada, al registrar devoluciones (D-004); el trigger
 * delivery_items_guard() (M-2) lo exige en la base de datos. B-1:
 * returned_quantity no es asignable en masa (se acumula con increment()).
 */
#[Fillable(['delivery_id', 'asset_id', 'asset_type_id', 'quantity'])]
class DeliveryItem extends Model
{
    /** @use HasFactory<DeliveryItemFactory> */
    use HasFactory;

    public $timestamps = false;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'returned_quantity' => 0,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'returned_quantity' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Delivery, $this>
     */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<AssetType, $this>
     */
    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /**
     * @return HasMany<DeliveryReturn, $this>
     */
    public function returns(): HasMany
    {
        return $this->hasMany(DeliveryReturn::class)->orderBy('id');
    }

    public function isSerialized(): bool
    {
        return $this->asset_id !== null;
    }

    /**
     * @return Attribute<int, never>
     */
    protected function pendingQuantity(): Attribute
    {
        return Attribute::get(fn () => $this->quantity - $this->returned_quantity);
    }

    /**
     * Ítems con unidades sin devolver.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->whereColumn($this->qualifyColumn('returned_quantity'), '<', $this->qualifyColumn('quantity'));
    }
}
