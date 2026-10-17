<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogSearch;
use Database\Factories\LocationTypeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tipo de ubicación administrable (RN-004, FR-003). No se borra, se
 * desactiva (RN-003).
 */
#[Fillable(['name', 'description'])]
class LocationType extends Model
{
    /** @use HasFactory<LocationTypeFactory> */
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
     * @return HasMany<Location, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
