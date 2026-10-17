<?php

namespace App\Http\Requests\Reportes;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Buscadores de Reportes (008): activo, empleado o tipo por cantidad para
 * elegir el reporte por artículo o por empleado. Exigen reportes.ver; sin
 * `search` responden una lista vacía.
 */
class ReportOptionsRequest extends FormRequest
{
    public const int LIMIT = 20;

    public function authorize(): bool
    {
        return $this->user()->can(Permission::ReportesVer->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Término recortado; null si viene vacío.
     */
    public function term(): ?string
    {
        $term = trim((string) $this->validated('search'));

        return $term === '' ? null : $term;
    }
}
