<?php

namespace App\Http\Requests\Inventario;

use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Models\StockLevel;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * US2 / FR-003: existencias por tipo y ubicación, paginadas (25 por defecto,
 * máximo 100), con filtros por categoría, tipo, ubicación y "bajo mínimo"
 * (RN-028). Requiere inventario.ver o, para el asistente de entrega (007),
 * entregas.gestionar.
 */
class ListStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', StockLevel::class)
            || $this->user()->can('viewForDelivery', StockLevel::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'asset_category_id' => ['nullable', 'integer'],
            'asset_type_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'below_min' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * B-2 (revisión de seguridad de 007): el filtro "bajo mínimo" (alertas de
     * stock, RN-028) solo aplica con inventario.ver; con acceso solo por
     * entregas.gestionar se ignora.
     */
    public function belowMin(): bool
    {
        return $this->boolean('below_min') && $this->user()->can('viewAny', StockLevel::class);
    }

    public function perPage(): int
    {
        return $this->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE);
    }
}
