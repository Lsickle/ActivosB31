<?php

namespace App\Rules;

use App\Enums\TrackingMode;
use App\Models\AssetType;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * D-007 (005): el tipo de un activo debe ser serializado (RN-007) y estar
 * disponible, es decir, activo y de una categoría activa (RN-003). Al editar
 * se puede conservar el tipo actual aunque ya no esté disponible.
 */
class SerializedAssetType implements ValidationRule
{
    public const string QUANTITY_MESSAGE = 'Los artículos de un tipo por cantidad se manejan en Inventario, no como activos.';

    public const string UNAVAILABLE_MESSAGE = 'El tipo de activo seleccionado está inactivo o su categoría está inactiva.';

    public function __construct(private readonly ?int $currentTypeId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);

        if ($id === false) {
            return;
        }

        $type = AssetType::query()->with('category:id,is_active')->find($id, ['id', 'asset_category_id', 'tracking_mode', 'is_active']);

        if ($type === null) {
            $fail('validation.exists')->translate();

            return;
        }

        if ($type->tracking_mode !== TrackingMode::Serialized) {
            $fail(self::QUANTITY_MESSAGE);

            return;
        }

        if ($id !== $this->currentTypeId && ! ($type->is_active && $type->category->is_active)) {
            $fail(self::UNAVAILABLE_MESSAGE);
        }
    }
}
