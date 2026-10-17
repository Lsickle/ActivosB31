<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\TrackingMode;
use App\Http\Requests\Concerns\NormalizesCatalogFields;
use App\Models\AssetCategory;
use App\Models\AssetType;
use App\Rules\ActiveCatalogParent;
use App\Rules\UniqueCaseInsensitive;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssetTypeRequest extends FormRequest
{
    use NormalizesCatalogFields {
        prepareForValidation as normalizeCatalogFields;
    }

    public const string TRACKING_MODE_LOCKED_MESSAGE = 'No se puede cambiar el modo de control: el tipo ya tiene activos registrados.';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->assetType());
    }

    /**
     * FR-006: si el tipo cambia de categoría sin reenviar el nombre, el
     * nombre actual se valida contra la categoría destino.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeCatalogFields();

        if ($this->has('asset_category_id') && ! $this->has('name')) {
            $this->merge(['name' => $this->assetType()->name]);
        }

        // D-008 (005): un tipo que pasa a serializado pierde su stock mínimo.
        if (! $this->has('min_stock') && $this->assetType()->min_stock !== null && $this->trackingMode() !== TrackingMode::Quantity->value) {
            $this->merge(['min_stock' => null]);
        }
    }

    /**
     * Modo de control resultante: el enviado o el actual.
     */
    private function trackingMode(): mixed
    {
        return $this->has('tracking_mode') ? $this->input('tracking_mode') : $this->assetType()->tracking_mode->value;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $assetType = $this->assetType();
        $categoryId = $this->has('asset_category_id')
            ? filter_var($this->input('asset_category_id'), FILTER_VALIDATE_INT)
            : $assetType->asset_category_id;

        return [
            // FR-007: puede conservar su categoría aunque esté inactiva.
            'asset_category_id' => [
                'sometimes', 'bail', 'required', 'integer',
                new ActiveCatalogParent(AssetCategory::class, StoreAssetTypeRequest::INACTIVE_CATEGORY_MESSAGE, $assetType->asset_category_id),
            ],
            ...$this->catalogFieldRules(new UniqueCaseInsensitive('asset_types', 'name', $assetType->id, [
                'asset_category_id' => $categoryId === false ? null : $categoryId,
            ]), partial: true),
            'tracking_mode' => [
                'sometimes', 'bail', 'required', Rule::enum(TrackingMode::class),
                // FR-009 (D-009): inmutable si el tipo ya tiene activos.
                function (string $attribute, mixed $value, Closure $fail) use ($assetType) {
                    if ($value !== $assetType->tracking_mode->value && $assetType->hasAssets()) {
                        $fail(self::TRACKING_MODE_LOCKED_MESSAGE);
                    }
                },
            ],
            'min_stock' => ['sometimes', ...StoreAssetTypeRequest::minStockRules($this->trackingMode())],
        ];
    }

    private function assetType(): AssetType
    {
        return $this->route('asset_type');
    }
}
