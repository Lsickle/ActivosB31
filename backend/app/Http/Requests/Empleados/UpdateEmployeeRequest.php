<?php

namespace App\Http\Requests\Empleados;

use App\Http\Requests\Concerns\ValidatesEmployeeFields;
use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Edición parcial de un empleado (FR-001). Requiere empleados.gestionar
 * (RN-022).
 */
class UpdateEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('employee'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return $this->employeeRules($employee);
    }
}
