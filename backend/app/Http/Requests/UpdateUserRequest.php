<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUserIdentifiers;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Edición de datos, rol (RN-013) y empleado vinculado (RN-015). La contraseña
 * se restablece aparte.
 */
class UpdateUserRequest extends FormRequest
{
    use NormalizesUserIdentifiers;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->route('user');

        return $this->user()->can('update', $user)
            && (! $this->changesEmployee($user) || $this->user()->can('linkEmployee', User::class));
    }

    /**
     * RN-015: cambiar o quitar el vínculo exige empleados.ver; reenviar el
     * mismo empleado (o no enviarlo) no.
     */
    private function changesEmployee(User $user): bool
    {
        if (! $this->exists('employee_id')) {
            return false;
        }

        $requested = $this->input('employee_id');

        return (string) ($requested ?? '') !== (string) ($user->employee_id ?? '');
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');
        $identifiers = $this->identifierRules($user->id);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'username' => ['sometimes', ...$identifiers['username']],
            'email' => ['sometimes', ...$identifiers['email']],
            // RN-017: puede conservar su rol actual aunque esté inactivo, pero
            // no recibir otro rol inactivo.
            'role_id' => [
                'sometimes', 'required', 'integer',
                Rule::exists('roles', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->orWhere('id', $user->role_id),
                ),
            ],
            // RN-015: empleado activo y libre; puede conservar el actual
            // aunque esté inactivo. null desvincula.
            'employee_id' => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('employees', 'id')->where(
                    fn ($query) => $query->where('is_active', true)->orWhere('id', $user->employee_id),
                ),
                Rule::unique('users', 'employee_id')->ignore($user->id),
            ],
        ];
    }
}
