<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Rules\UniqueCaseInsensitive;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rol creado desde la aplicación (RN-010): nace sin permisos, sin código y
 * sin la marca de rol del sistema.
 */
class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Role::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'max:80', new UniqueCaseInsensitive('roles', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
