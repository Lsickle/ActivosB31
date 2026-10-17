<?php

namespace App\Http\Requests\Entregas;

use App\Enums\ReturnCondition;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/deliveries/{delivery}/returns (FR-003, D-004; contrato
 * specs/007-modulo-entregas/contracts/api.md). Requiere entregas.gestionar.
 *
 * Cada renglón: delivery_item_id, quantity, condition (good | damaged |
 * lost), to_location_id (lo exige RegisterDeliveryReturn según el artículo:
 * serializado bueno o dañado; por cantidad bueno) y notes (obligatoria si
 * es perdido, RN-026).
 *
 * Pertenencia a la entrega, cantidad pendiente y ubicación disponible las
 * valida App\Actions\Entregas\RegisterDeliveryReturn (items.N.*).
 */
class StoreDeliveryReturnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('registerReturn', $this->route('delivery'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:'.StoreDeliveryRequest::MAX_ITEMS],
            'items.*' => ['array'],
            'items.*.delivery_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.StoreDeliveryRequest::MAX_QUANTITY],
            'items.*.condition' => ['required', Rule::enum(ReturnCondition::class)],
            'items.*.to_location_id' => ['nullable', 'integer', Rule::exists('locations', 'id')],
            'items.*.notes' => [
                'nullable', 'required_if:items.*.condition,'.ReturnCondition::Lost->value, 'string', 'max:'.StoreDeliveryRequest::NOTES_MAX,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Indica al menos un artículo a devolver.',
            'items.min' => 'Indica al menos un artículo a devolver.',
            'items.*.quantity.min' => 'La cantidad debe ser un número entero mayor que 0.',
            'items.*.quantity.integer' => 'La cantidad debe ser un número entero mayor que 0.',
            'items.*.condition.required' => 'Indica el estado.',
            'items.*.notes.required_if' => 'Describe qué pasó con el artículo perdido.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'items' => 'artículos',
            'items.*.delivery_item_id' => 'artículo',
            'items.*.quantity' => 'cantidad',
            'items.*.condition' => 'estado',
            'items.*.to_location_id' => 'ubicación',
            'items.*.notes' => 'observaciones',
        ];
    }

    /**
     * Renglones validados con el índice del payload (claves items.N.*).
     *
     * @return array<int, array{delivery_item_id: int, quantity: int, condition: string, to_location_id?: int|null, notes?: string|null}>
     */
    public function items(): array
    {
        $items = $this->validated('items');
        ksort($items);

        return $items;
    }
}
