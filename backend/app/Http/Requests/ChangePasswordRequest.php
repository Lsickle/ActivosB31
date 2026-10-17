<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;

/**
 * Cambio de contraseña propio (RN-014, FR-010). La contraseña actual se
 * verifica contra el usuario autenticado, sea sesión web o token Bearer.
 */
class ChangePasswordRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $currentHash = $this->user()->password;

        return [
            'current_password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($currentHash) {
                    if (! is_string($value) || ! Hash::check($value, $currentHash)) {
                        $fail('La contraseña actual no es correcta.');
                    }
                },
            ],
            'password' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail) use ($currentHash) {
                    if (is_string($value) && Hash::check($value, $currentHash)) {
                        $fail('La nueva contraseña debe ser distinta de la actual.');
                    }
                },
                'confirmed',
                PasswordRules::policy(),
            ],
        ];
    }
}
