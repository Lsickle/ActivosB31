<?php

namespace App\Http\Requests\Inventario;

use App\Actions\Inventario\OpenInventoryCount;
use App\Enums\CountStatus;
use App\Models\InventoryCount;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/inventory/counts (US3, D-004): abre un conteo en una ubicación;
 * un solo conteo abierto por ubicación (US3-3). Requiere
 * inventario.gestionar.
 */
class StoreInventoryCountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryCount::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id' => [
                'bail', 'required', 'integer', Rule::exists('locations', 'id'),
                Rule::unique('inventory_counts', 'location_id')->where('status', CountStatus::Open->value),
            ],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['location_id.unique' => OpenInventoryCount::ALREADY_OPEN_MESSAGE];
    }
}
