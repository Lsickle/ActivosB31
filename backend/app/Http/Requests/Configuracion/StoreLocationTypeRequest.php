<?php

namespace App\Http\Requests\Configuracion;

use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\LocationType;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de tipo de ubicación (RN-004, FR-003): nace activo.
 */
class StoreLocationTypeRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', LocationType::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->catalogFieldRules(new UniqueCaseInsensitive('location_types', 'name'));
    }
}
