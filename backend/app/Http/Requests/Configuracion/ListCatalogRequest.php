<?php

namespace App\Http\Requests\Configuracion;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Listado paginado de un catálogo de Configuración (FR-013, D-004): búsqueda
 * por nombre, filtro de estado y paginación (25 por defecto, máximo 100).
 */
abstract class ListCatalogRequest extends FormRequest
{
    public const int DEFAULT_PER_PAGE = 25;

    /**
     * @return class-string<Model>
     */
    abstract protected function model(): string;

    /**
     * Filtros propios del catálogo.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function extraRules(): array
    {
        return [];
    }

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', $this->model());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'is_active' => ['nullable', 'in:0,1,true,false'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ...$this->extraRules(),
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', self::DEFAULT_PER_PAGE);
    }
}
