<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesUserIdentifiers;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Alta de usuario con contraseña temporal (RN-014) y un rol activo (RN-013).
 */
class StoreUserRequest extends FormRequest
{
    use NormalizesUserIdentifiers;

    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
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
            'password' => ['required', 'string', 'confirmed', PasswordRules::policy()],
        ];
    }
}
