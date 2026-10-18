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
 *
 * R-1 (revisión de seguridad de 008): el formato y los filtros se leen solo
 * de la query string, igual que el limitador `report-downloads`. Un GET con
 * cuerpo JSON no puede pedir un archivo sin pasar por el límite ni aplicar
 * filtros que no se ven en la URL. Los controladores leen los filtros con
 * validated()/filterId(), nunca con input().
 */
class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ReportesVer->value);
    }

    /**
     * R-1: solo la query string.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->query();
    }

    /**
     * Filtros propios del reporte.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    protected function filterRules(): array
    {
        $hasFrom = filled($this->query('from'));

        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', ...($hasFrom ? ['after_or_equal:from'] : [])],
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

    /**
     * Id validado de un filtro (p. ej. location_id); null si no vino.
     */
    public function filterId(string $key): ?int
    {
        $value = $this->validated($key);

        return blank($value) ? null : (int) $value;
    }

    /**
     * Filtros validados tal como llegaron en la query (sin `format`), para la
     * bitácora de exportaciones (R-3).
     *
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        return $this->safe()->except('format');
    }
}
