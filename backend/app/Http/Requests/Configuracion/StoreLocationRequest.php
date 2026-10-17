<?php

namespace App\Http\Requests\Configuracion;

use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\Location;
use App\Models\LocationType;
use App\Rules\ActiveCatalogParent;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de ubicación (FR-004): tipo de ubicación activo (FR-007, US3-1) y
 * nombre único global (FR-006). Lista plana, sin ubicación padre (RN-002).
 */
class StoreLocationRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public const string INACTIVE_TYPE_MESSAGE = 'El tipo de ubicación seleccionado está inactivo.';

    public function authorize(): bool
    {
        return $this->user()->can('create', Location::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'location_type_id' => [
                'bail', 'required', 'integer',
                new ActiveCatalogParent(LocationType::class, self::INACTIVE_TYPE_MESSAGE),
            ],
            ...$this->catalogFieldRules(new UniqueCaseInsensitive('locations', 'name')),
        ];
    }
}
