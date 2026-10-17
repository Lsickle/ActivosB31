<?php

namespace App\Http\Requests\Reportes;

use Illuminate\Validation\Rule;

/**
 * US3 (008): reporte de un tipo por cantidad, con rango (RN-033) y
 * ubicación opcional.
 */
class AssetTypeReportRequest extends ReportRequest
{
    protected function filterRules(): array
    {
        return [
            ...parent::filterRules(),
            'location_id' => ['bail', 'nullable', 'integer', Rule::exists('locations', 'id')],
        ];
    }
}
