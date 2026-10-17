<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogSearch;
use Database\Factories\AssetCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Categoría de activo (FR-001): familia de bienes. No se borra, se desactiva
 * (RN-003); desactivarla no desactiva sus tipos (FR-008).
 */
#[Fillable(['name', 'description'])]
class AssetCategory extends Model
{
    /** @use HasFactory<AssetCategoryFactory> */
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
        ];
    }

    /**
     * @return HasMany<AssetType, $this>
     */
    public function assetTypes(): HasMany
    {
        return $this->hasMany(AssetType::class);
    }
}
