<?php

namespace App\Http\Requests\Empleados;

use App\Http\Requests\Concerns\ValidatesEmployeeFields;
use App\Models\Employee;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta de empleado (US1-1, FR-003): nace activo. Requiere
 * empleados.gestionar (RN-022).
 */
class StoreEmployeeRequest extends FormRequest
{
    use ValidatesEmployeeFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', Employee::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->employeeRules();
    }
}
