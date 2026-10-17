<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\ValidatesAssetFields;
use App\Models\Asset;
use App\Rules\AvailableLocation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de activo serializado (US1-1, FR-001, FR-002): nace Disponible
 * (RN-005) en la ubicación indicada, opcionalmente con foto (multipart).
 * Requiere activos.gestionar (FR-007).
 */
class StoreAssetRequest extends FormRequest
{
    use ValidatesAssetFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', Asset::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...$this->assetRules(),
            'location_id' => ['bail', 'nullable', 'integer', new AvailableLocation],
        ];
    }
}
