<?php

namespace App\Http\Requests\Entregas;

use App\Enums\DeliveryKind;
use App\Enums\DeliveryStatus;
use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Models\Delivery;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * US3: listado paginado de entregas (25 por defecto, máximo 100), de la más
 * reciente a la más antigua, con búsqueda por consecutivo o nombre del
 * empleado y filtros por empleado, tipo, estado derivado (D-005) y fechas de
 * entrega (días en America/Bogota). Requiere entregas.ver (FR-006).
 */
class ListDeliveriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Delivery::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'employee_id' => ['nullable', 'integer'],
            'kind' => ['nullable', Rule::enum(DeliveryKind::class)],
            'status' => ['nullable', Rule::enum(DeliveryStatus::class)],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE);
    }
}
