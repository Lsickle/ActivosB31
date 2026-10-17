<?php

namespace App\Http\Requests\Inventario;

use App\Enums\CountStatus;
use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Models\InventoryCount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/inventory/counts (US3): listado paginado (25 por defecto, máximo
 * 100) del más reciente al más antiguo, con filtros por estado y ubicación.
 * Requiere inventario.ver.
 */
class ListInventoryCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', InventoryCount::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(CountStatus::class)],
            'location_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE);
    }
}
