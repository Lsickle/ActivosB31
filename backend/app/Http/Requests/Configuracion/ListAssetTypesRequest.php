<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\TrackingMode;
use App\Models\AssetType;
use Illuminate\Validation\Rule;

/**
 * FR-002: además filtra por categoría y modo de control (D-004).
 */
class ListAssetTypesRequest extends ListCatalogRequest
{
    protected function model(): string
    {
        return AssetType::class;
    }

    protected function extraRules(): array
    {
        return [
            'asset_category_id' => ['nullable', 'integer'],
            'tracking_mode' => ['nullable', Rule::enum(TrackingMode::class)],
        ];
    }
}
