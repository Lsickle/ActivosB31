<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * Unicidad sin distinguir mayúsculas (FR-008), alineada con los índices
 * únicos sobre lower(columna) de la base de datos. El alcance opcional
 * (columna => valor) restringe la comparación, p. ej. tipos de activo únicos
 * por categoría (FR-006, D-007 de 002-modulo-configuracion).
 */
class UniqueCaseInsensitive implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly ?int $ignoreId = null,
        /** @var array<string, mixed> */
        private readonly array $scope = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $taken = DB::table($this->table)
            ->whereRaw("lower({$this->column}) = lower(?)", [$value])
            ->where($this->scope)
            ->when($this->ignoreId !== null, fn ($query) => $query->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
