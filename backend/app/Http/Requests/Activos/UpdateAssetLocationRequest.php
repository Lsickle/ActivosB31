<?php

namespace App\Http\Requests\Activos;

use App\Rules\AvailableLocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Traslado de un activo a una ubicación disponible (US2, edge case de 005).
 * Requiere activos.gestionar (FR-007).
 */
class UpdateAssetLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('asset'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_id' => ['bail', 'required', 'integer', new AvailableLocation],
        ];
    }
}
