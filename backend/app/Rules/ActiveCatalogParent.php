<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * FR-007: el padre de un registro de catálogo (categoría de un tipo de
 * activo, tipo de una ubicación) debe existir y estar activo. Al editar se
 * admite conservar el padre actual aunque esté inactivo (edge case de la
 * spec 002); cambiar a otro padre exige que esté activo.
 */
class ActiveCatalogParent implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(
        private readonly string $model,
        private readonly string $inactiveMessage,
        private readonly ?int $currentParentId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false) {
            return;
        }

        $parent = $this->model::query()->find($id, ['id', 'is_active']);

        if ($parent === null) {
            $fail('validation.exists')->translate();

            return;
        }

        if (! $parent->is_active && $id !== $this->currentParentId) {
            $fail($this->inactiveMessage);
        }
    }
}
