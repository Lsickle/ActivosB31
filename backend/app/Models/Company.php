<?php

namespace App\Models;

use App\Models\Concerns\HasCatalogSearch;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Compañía o turno de los empleados (RN-036), catálogo de Configuración
 * sin precarga (D-001). No se borra, se desactiva (RN-003).
 */
#[Fillable(['name', 'description'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
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
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
