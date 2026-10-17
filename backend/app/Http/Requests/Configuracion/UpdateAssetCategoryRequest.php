<?php

namespace App\Http\Requests\Configuracion;

use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\AssetCategory;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAssetCategoryRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('asset_category'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var AssetCategory $category */
        $category = $this->route('asset_category');

        return $this->catalogFieldRules(new UniqueCaseInsensitive('asset_categories', 'name', $category->id), partial: true);
    }
}
