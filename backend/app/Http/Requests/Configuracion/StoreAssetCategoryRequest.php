<?php

namespace App\Http\Requests\Configuracion;

use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\AssetCategory;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de categoría de activo (FR-001): nace activa.
 */
class StoreAssetCategoryRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', AssetCategory::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->catalogFieldRules(new UniqueCaseInsensitive('asset_categories', 'name'));
    }
}
