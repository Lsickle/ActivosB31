<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogSearch;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ubicación (FR-004): lista plana, sin ubicación padre (RN-002); tiene un
 * tipo de ubicación.
 */
#[Fillable(['location_type_id', 'name', 'description'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
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
     * @return BelongsTo<LocationType, $this>
     */
    public function locationType(): BelongsTo
    {
        return $this->belongsTo(LocationType::class);
    }

    /**
     * Disponible para registros nuevos: activa y de un tipo de ubicación
     * activo (FR-008, FR-010).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->active()->whereHas('locationType', fn (Builder $type) => $type->where('is_active', true));
    }
}
