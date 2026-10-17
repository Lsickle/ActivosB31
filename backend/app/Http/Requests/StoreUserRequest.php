<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUserIdentifiers;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de usuario con contraseña temporal (RN-014), un rol activo (RN-013) y,
 * opcionalmente, un empleado activo sin otro usuario vinculado (RN-015).
 */
class StoreUserRequest extends FormRequest
{
    use NormalizesUserIdentifiers;

    public function authorize(): bool
    {
        // RN-015: vincular un empleado exige además empleados.ver (403, como el
        // resto de la autorización; no se valida el cuerpo sin permiso).
        return $this->user()->can('create', User::class)
            && ($this->input('employee_id') === null || $this->user()->can('linkEmployee', User::class));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            ...$this->identifierRules(),
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')->where('is_active', true)],
            'employee_id' => [
                'nullable', 'integer',
                Rule::exists('employees', 'id')->where('is_active', true),
                Rule::unique('users', 'employee_id'),
            ],
            'password' => ['required', 'string', 'confirmed', PasswordRules::policy()],
        ];
    }
}
