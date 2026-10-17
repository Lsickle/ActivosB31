<?php

namespace App\Http\Requests\Concerns;

use App\Models\Company;
use App\Models\Employee;
use App\Models\Position;
use App\Rules\ActiveCatalogParent;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Campos del empleado (RN-021, FR-003) comunes al alta y la edición.
 *
 * D-002: el tipo de documento se guarda en mayúsculas y el número solo con
 * letras y dígitos en mayúsculas ("1.234.567" → "1234567"). Los nombres
 * colapsan espacios internos; los opcionales vacíos se guardan como null.
 */
trait ValidatesEmployeeFields
{
    public const string INACTIVE_POSITION_MESSAGE = 'El cargo seleccionado está inactivo.';

    public const string INACTIVE_COMPANY_MESSAGE = 'La compañía seleccionada está inactiva.';

    /**
     * @var list<string>
     */
    private const array OPTIONAL_TEXT_FIELDS = ['phone', 'email', 'shirt_size', 'pants_size', 'boot_size', 'glove_size', 'hire_date'];

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('document_type'))) {
            $normalized['document_type'] = mb_strtoupper(trim($this->input('document_type')));
        }

        if (is_string($this->input('document_number'))) {
            $normalized['document_number'] = Employee::normalizeDocumentNumber($this->input('document_number'));
        }

        // FR-002: al cambiar solo el tipo, la unicidad se valida con el
        // número actual (si no, la detectaría la base de datos).
        $employee = $this->route('employee');

        if ($employee instanceof Employee && $this->has('document_type') && ! $this->has('document_number')) {
            $normalized['document_number'] = $employee->document_number;
        }

        foreach (['first_name', 'last_name', 'phone'] as $field) {
            if (is_string($this->input($field))) {
                $normalized[$field] = trim((string) preg_replace('/\s+/u', ' ', $this->input($field)));
            }
        }

        foreach (self::OPTIONAL_TEXT_FIELDS as $field) {
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
            'hire_date.before_or_equal' => 'La fecha de ingreso no puede ser futura.',
        ];
    }

    /**
     * Al editar, los campos son opcionales en el cuerpo, pero los
     * obligatorios no pueden quedar vacíos; el cargo y la compañía actuales
     * se conservan aunque estén inactivos (US2-1).
     *
     * @return array<string, array<mixed>>
     */
    protected function employeeRules(?Employee $employee = null): array
    {
        $sometimes = $employee !== null ? ['sometimes'] : [];
        $documentType = is_string($this->input('document_type')) ? $this->input('document_type') : $employee?->document_type;

        // FR-002: unicidad por tipo + número, incluidos los inactivos.
        $uniqueDocument = Rule::unique('employees', 'document_number')
            ->where('document_type', (string) $documentType)
            ->ignore($employee?->id);

        $today = Carbon::now(Employee::LOCAL_TIMEZONE)->toDateString();

        return [
            'document_type' => [...$sometimes, 'bail', 'required', 'string', Rule::in(Employee::DOCUMENT_TYPES)],
            'document_number' => [...$sometimes, 'bail', 'required', 'string', 'max:20', $uniqueDocument],
            'first_name' => [...$sometimes, 'bail', 'required', 'string', 'max:80'],
            'last_name' => [...$sometimes, 'bail', 'required', 'string', 'max:80'],
            'position_id' => [
                ...$sometimes, 'bail', 'required', 'integer',
                new ActiveCatalogParent(Position::class, self::INACTIVE_POSITION_MESSAGE, $employee?->position_id),
            ],
            'company_id' => [
                ...$sometimes, 'bail', 'nullable', 'integer',
                new ActiveCatalogParent(Company::class, self::INACTIVE_COMPANY_MESSAGE, $employee?->company_id),
            ],
            // Dígitos, espacios, "+", "-" y paréntesis, con al menos un dígito.
            'phone' => [...$sometimes, 'nullable', 'string', 'max:30', 'regex:/^(?=.*\d)[\d +()\-]+$/'],
            'email' => [...$sometimes, 'nullable', 'string', 'max:255', 'email'],
            'hire_date' => [...$sometimes, 'bail', 'nullable', 'date_format:Y-m-d', "before_or_equal:{$today}"],
            'shirt_size' => [...$sometimes, 'nullable', 'string', 'max:10'],
            'pants_size' => [...$sometimes, 'nullable', 'string', 'max:10'],
            'boot_size' => [...$sometimes, 'nullable', 'string', 'max:10'],
            'glove_size' => [...$sometimes, 'nullable', 'string', 'max:10'],
        ];
    }
}
