<?php

namespace App\Http\Requests\Configuracion;

use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\LocationType;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationTypeRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('location_type'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var LocationType $locationType */
        $locationType = $this->route('location_type');

        return $this->catalogFieldRules(new UniqueCaseInsensitive('location_types', 'name', $locationType->id), partial: true);
    }
}
