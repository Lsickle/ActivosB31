<?php

namespace App\Models;

use App\Enums\TrackingMode;
use App\Models\Concerns\HasCatalogSearch;
use Database\Factories\AssetTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tipo de activo (FR-002): pertenece a una categoría y define el modo de
 * control serializado o por cantidad (RN-007).
 */
#[Fillable(['asset_category_id', 'name', 'description', 'tracking_mode'])]
class AssetType extends Model
{
    /** @use HasFactory<AssetTypeFactory> */
    use HasCatalogSearch, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'tracking_mode' => TrackingMode::class,
        ];
    }

    /**
     * @return BelongsTo<AssetCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    /**
     * Disponible para registros nuevos: activo y de una categoría activa
     * (FR-008, FR-010).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->active()->whereHas('category', fn (Builder $category) => $category->where('is_active', true));
    }

    /**
     * FR-009 (D-009): el modo de control no cambia si el tipo ya tiene
     * activos. Sin efecto hasta que exista el registro de activos.
     */
    public function hasAssets(): bool
    {
        return false;
    }
}
