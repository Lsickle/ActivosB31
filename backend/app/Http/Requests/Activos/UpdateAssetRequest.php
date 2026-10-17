<?php

namespace App\Http\Requests\Activos;

use App\Http\Requests\Concerns\ValidatesAssetFields;
use App\Models\Asset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición parcial de los datos de un activo (FR-001). PATCH o POST
 * (multipart, con foto opcional). Estado y ubicación se cambian con sus
 * propias rutas (D-001). Requiere activos.gestionar (FR-007).
 */
class UpdateAssetRequest extends FormRequest
{
    use ValidatesAssetFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->asset());
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->assetRules($this->asset());
    }

    public function asset(): Asset
    {
        return $this->route('asset');
    }
}
