<?php

namespace App\Http\Requests\Activos;

use App\Enums\AssetStatus;
use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Models\Asset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US1-5: listado paginado de activos (25 por defecto, máximo 100) con
 * búsqueda por placa, serial, marca o modelo y filtros por categoría, tipo,
 * estado, ubicación y poseedor. Requiere activos.ver (FR-007).
 */
class ListAssetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Asset::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'asset_category_id' => ['nullable', 'integer'],
            'asset_type_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(AssetStatus::class)],
            'location_id' => ['nullable', 'integer'],
            'holder_employee_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE);
    }
}
