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

/**
 * Alta de tipo de activo (FR-002): categoría activa (FR-007), nombre único
 * dentro de la categoría (FR-006), modo de control obligatorio (RN-007) y
 * stock mínimo opcional en tipos por cantidad (RN-028, D-008 de 005).
 */
class StoreAssetTypeRequest extends FormRequest
{
    use NormalizesCatalogFields;

    public const string INACTIVE_CATEGORY_MESSAGE = 'La categoría seleccionada está inactiva.';

    public const string MIN_STOCK_QUANTITY_ONLY_MESSAGE = 'El stock mínimo solo aplica a tipos por cantidad.';

    public function authorize(): bool
    {
        return $this->user()->can('create', AssetType::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $categoryId = filter_var($this->input('asset_category_id'), FILTER_VALIDATE_INT);

        return [
            'asset_category_id' => [
                'bail', 'required', 'integer',
                new ActiveCatalogParent(AssetCategory::class, self::INACTIVE_CATEGORY_MESSAGE),
            ],
            ...$this->catalogFieldRules(new UniqueCaseInsensitive('asset_types', 'name', scope: [
                'asset_category_id' => $categoryId === false ? null : $categoryId,
            ])),
            'tracking_mode' => ['required', Rule::enum(TrackingMode::class)],
            'min_stock' => self::minStockRules($this->input('tracking_mode')),
        ];
    }

    /**
     * RN-028 / D-008 (005): entero >= 0 opcional; null siempre se acepta, un
     * valor solo en tipos por cantidad.
     *
     * @return list<mixed>
     */
    public static function minStockRules(mixed $trackingMode): array
    {
        return [
            'nullable', 'integer', 'min:0', 'max:2147483647',
            function (string $attribute, mixed $value, Closure $fail) use ($trackingMode) {
                if ($value !== null && $trackingMode !== TrackingMode::Quantity->value) {
                    $fail(self::MIN_STOCK_QUANTITY_ONLY_MESSAGE);
                }
            },
        ];
    }
}
