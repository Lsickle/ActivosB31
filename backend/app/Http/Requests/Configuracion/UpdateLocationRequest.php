<?php

namespace App\Http\Requests\Configuracion;

use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\Location;
use App\Models\LocationType;
use App\Rules\ActiveCatalogParent;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('location'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Location $location */
        $location = $this->route('location');

        return [
            // FR-007 / US3-4: puede conservar su tipo aunque esté inactivo.
            'location_type_id' => [
                'sometimes', 'bail', 'required', 'integer',
                new ActiveCatalogParent(LocationType::class, StoreLocationRequest::INACTIVE_TYPE_MESSAGE, $location->location_type_id),
            ],
            ...$this->catalogFieldRules(new UniqueCaseInsensitive('locations', 'name', $location->id), partial: true),
        ];
    }
}
