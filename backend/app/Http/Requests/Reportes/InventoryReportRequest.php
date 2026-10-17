<?php

namespace App\Http\Requests\Reportes;

use App\Enums\AssetStatus;
use Illuminate\Validation\Rule;

/**
 * US1 (008): inventario general, filtrable por categoría, tipo, ubicación y
 * estado (el estado solo filtra los serializados). Sin rango de fechas: es
 * una foto de las existencias actuales.
 */
class InventoryReportRequest extends ReportRequest
{
    protected function filterRules(): array
    {
        return [
            'asset_category_id' => ['bail', 'nullable', 'integer', Rule::exists('asset_categories', 'id')],
            'asset_type_id' => ['bail', 'nullable', 'integer', Rule::exists('asset_types', 'id')],
            'location_id' => ['bail', 'nullable', 'integer', Rule::exists('locations', 'id')],
            'status' => ['nullable', Rule::enum(AssetStatus::class)],
        ];
    }
}
