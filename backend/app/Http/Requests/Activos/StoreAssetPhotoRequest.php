<?php

namespace App\Http\Requests\Activos;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Subir o reemplazar la foto de un activo (FR-008). Requiere
 * activos.gestionar (FR-007).
 */
class StoreAssetPhotoRequest extends FormRequest
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
        return ['photo' => AssetPhotoRules::rules(required: true)];
    }
}
