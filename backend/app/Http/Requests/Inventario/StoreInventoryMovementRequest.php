<?php

namespace App\Http\Requests\Inventario;

use App\Enums\MovementType;
use App\Models\InventoryMovement;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/inventory/movements (US1, FR-001, D-002). Solo las clases
 * manuales (entrada, salida, traslado, ajuste); entrega, devolución y pérdida
 * las genera Entregas (007). Requiere inventario.gestionar (FR-006).
 *
 * - entry: to_location_id. exit: from_location_id + reason.
 * - transfer: ambas, distintas.
 * - adjustment: exactamente una (to suma, from resta) + reason.
 *
 * RN-007, D-003 y la existencia suficiente los valida RecordStockMovement.
 */
class StoreInventoryMovementRequest extends FormRequest
{
    public const int MAX_QUANTITY = 2_147_483_647;

    public function authorize(): bool
    {
        return $this->user()->can('create', InventoryMovement::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $type = $this->movementType();
        $location = ['integer', Rule::exists('locations', 'id')];

        return [
            'type' => ['required', Rule::enum(MovementType::class)->only(MovementType::manual())],
            'asset_type_id' => ['required', 'integer', Rule::exists('asset_types', 'id')],
            'quantity' => ['required', 'integer', 'min:1', 'max:'.self::MAX_QUANTITY],
            'from_location_id' => match ($type) {
                MovementType::Exit, MovementType::Transfer => ['required', ...$location],
                MovementType::Entry => ['prohibited'],
                default => ['nullable', ...$location],
            },
            'to_location_id' => match ($type) {
                MovementType::Entry => ['required', ...$location],
                MovementType::Transfer => ['required', ...$location, 'different:from_location_id'],
                MovementType::Exit => ['prohibited'],
                MovementType::Adjustment => ['required_without:from_location_id', 'prohibits:from_location_id', 'nullable', ...$location],
                default => ['nullable', ...$location],
            },
            'reason' => [$type?->requiresReason() ? 'required' : 'nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'to_location_id.different' => 'El destino debe ser distinto del origen.',
            'to_location_id.required_without' => 'Indica la ubicación que aumenta (destino) o la que disminuye (origen).',
            'to_location_id.prohibits' => 'El ajuste lleva una sola ubicación: destino para aumentar u origen para disminuir.',
            'reason.required' => 'Indica el motivo.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'type' => 'clase de movimiento',
            'quantity' => 'cantidad',
            'from_location_id' => 'ubicación de origen',
            'to_location_id' => 'ubicación de destino',
        ];
    }

    public function movementType(): ?MovementType
    {
        return MovementType::tryFrom((string) $this->input('type'));
    }
}
