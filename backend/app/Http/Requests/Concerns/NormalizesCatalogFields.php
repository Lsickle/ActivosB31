<?php

namespace App\Http\Requests\Concerns;

use App\Rules\UniqueCaseInsensitive;

/**
 * Campos comunes de los catálogos de Configuración (nombre y descripción).
 *
 * D-002: TrimStrings ya recorta los extremos; aquí se colapsan los espacios
 * internos repetidos del nombre (conservando la capitalización) y una
 * descripción vacía se guarda como null.
 */
trait NormalizesCatalogFields
{
    public const int NAME_MAX = 120;

    public const int DESCRIPTION_MAX = 500;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('name'))) {
            $normalized['name'] = trim((string) preg_replace('/\s+/u', ' ', $this->input('name')));
        }

        if ($this->has('description') && is_string($this->input('description')) && trim($this->input('description')) === '') {
            $normalized['description'] = null;
        }

        $this->merge($normalized);
    }

    /**
     * Reglas de nombre y descripción (FR-006, FR-012). Al editar, ambos son
     * opcionales en el cuerpo, pero si viene el nombre no puede quedar vacío.
     *
     * @return array<string, array<mixed>>
     */
    protected function catalogFieldRules(UniqueCaseInsensitive $unique, bool $partial = false): array
    {
        $sometimes = $partial ? ['sometimes'] : [];

        return [
            'name' => [...$sometimes, 'bail', 'required', 'string', 'max:'.self::NAME_MAX, $unique],
            'description' => [...$sometimes, 'nullable', 'string', 'max:'.self::DESCRIPTION_MAX],
        ];
    }
}
