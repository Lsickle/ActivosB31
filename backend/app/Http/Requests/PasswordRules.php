<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rules\Password;

/**
 * Política de contraseñas (FR-010): al menos 10 caracteres, con letras y
 * números. Sin verificación contra filtraciones (requeriría un servicio
 * externo; ver supuestos de la spec 001-rbac).
 */
final class PasswordRules
{
    public static function policy(): Password
    {
        return Password::min(10)->letters()->numbers();
    }
}
