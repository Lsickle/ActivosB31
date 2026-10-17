<?php

namespace App\Http\Requests\Reportes;

use App\Enums\Permission;
use App\Reports\DateRange;
use App\Reports\Export\ReportResponder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Petición de un reporte (008): reportes.ver (FR-003), `format`
 * json|xlsx|pdf (D-002) y rango opcional `from`/`to` YYYY-MM-DD inclusivo
 * (RN-033); `to` anterior a `from` → 422 en `to`.
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ReportesVer->value);
    }

    /**
     * Filtros propios del reporte.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function filterRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($this->filled('from') ? ['after_or_equal:from'] : [])],
        ];
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', Rule::in(ReportResponder::FORMATS)],
            ...$this->filterRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'from' => 'desde',
            'to' => 'hasta',
            'format' => 'formato',
            'asset_category_id' => 'categoría',
            'asset_type_id' => 'tipo',
            'location_id' => 'ubicación',
            'status' => 'estado',
        ];
    }

    public function reportFormat(): string
    {
        return $this->validated('format') ?? ReportResponder::FORMAT_JSON;
    }

    public function dateRange(): DateRange
    {
        return new DateRange($this->validated('from'), $this->validated('to'));
    }
}
