<?php

namespace App\Http\Requests\Inventario;

use App\Enums\MovementType;
use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Models\InventoryMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FR-003: historial de movimientos paginado (25 por defecto, máximo 100) con
 * filtros por clase, tipo, ubicación (origen o destino) y rango de fechas
 * YYYY-MM-DD inclusivo en America/Bogota. Requiere inventario.ver.
 */
class ListInventoryMovementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', InventoryMovement::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(MovementType::class)],
            'asset_type_id' => ['nullable', 'integer'],
            'location_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['type' => 'clase de movimiento', 'from' => 'desde', 'to' => 'hasta'];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE);
    }
}
