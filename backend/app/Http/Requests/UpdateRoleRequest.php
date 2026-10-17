<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Rules\UniqueCaseInsensitive;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('role'));
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Role $role */
        $role = $this->route('role');

        return [
            'name' => [
                'sometimes', 'bail', 'required', 'string', 'max:80',
                // RN-020: los roles precargados conservan su nombre; se acepta
                // reenviar el mismo nombre (formulario completo).
                function (string $attribute, mixed $value, Closure $fail) use ($role) {
                    if ($role->is_system && $value !== $role->name) {
                        $fail('Los roles precargados no se pueden renombrar.');
                    }
                },
                new UniqueCaseInsensitive('roles', 'name', $role->id),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
