<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\Activos\AssetPhotoRules;
use App\Models\Asset;
use App\Rules\SerializedAssetType;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Support\Carbon;

/**
 * Datos del activo serializado (FR-001, RN-008) comunes al alta y la
 * edición. El estado, la ubicación y el poseedor NO se editan aquí: tienen
 * sus propias rutas y acciones para quedar en el historial (D-001, D-002).
 *
 * D-006: la placa se recorta y pasa a mayúsculas. Los textos opcionales
 * vacíos se guardan como null.
 */
trait ValidatesAssetFields
{
    /**
     * @var list<string>
     */
    private const array OPTIONAL_FIELDS = [
        'serial', 'brand', 'model', 'acquisition_date', 'acquisition_value', 'useful_life_months', 'expires_on', 'notes',
    ];

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('plate'))) {
            $normalized['plate'] = mb_strtoupper(trim($this->input('plate')));
        }

        foreach (['serial', 'brand', 'model', 'notes'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim($this->input($field));
            }
        }

        foreach (self::OPTIONAL_FIELDS as $field) {
            $value = $normalized[$field] ?? $this->input($field);

            if ($this->has($field) && is_string($value) && trim($value) === '') {
                $normalized[$field] = null;
            }
        }

        $this->merge($normalized);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'acquisition_date.before_or_equal' => 'La fecha de adquisición no puede ser futura.',
        ];
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function assetRules(?Asset $asset = null): array
    {
        $sometimes = $asset !== null ? ['sometimes'] : [];
        $today = Carbon::now(Asset::LOCAL_TIMEZONE)->toDateString();

        return [
            'asset_type_id' => [...$sometimes, 'bail', 'required', 'integer', new SerializedAssetType($asset?->asset_type_id)],
            'plate' => [...$sometimes, 'bail', 'required', 'string', 'max:50', new UniqueCaseInsensitive('assets', 'plate', $asset?->id)],
            'serial' => [...$sometimes, 'nullable', 'string', 'max:100'],
            'brand' => [...$sometimes, 'nullable', 'string', 'max:80'],
            'model' => [...$sometimes, 'nullable', 'string', 'max:80'],
            'acquisition_date' => [...$sometimes, 'bail', 'nullable', 'date_format:Y-m-d', "before_or_equal:{$today}"],
            'acquisition_value' => [...$sometimes, 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
            'useful_life_months' => [...$sometimes, 'nullable', 'integer', 'min:1', 'max:1200'],
            'expires_on' => [...$sometimes, 'nullable', 'date_format:Y-m-d'],
            'notes' => [...$sometimes, 'nullable', 'string', 'max:1000'],
            'photo' => AssetPhotoRules::rules(),
        ];
    }
}
