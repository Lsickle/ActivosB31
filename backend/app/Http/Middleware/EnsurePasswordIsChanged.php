<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * RN-014 / FR-009 (D-004): mientras el usuario tenga la contraseña temporal
 * pendiente de cambio, solo puede consultar su sesión, cambiar la contraseña
 * o cerrar sesión; esas tres rutas quedan fuera de este middleware en
 * routes/api.php.
 */
class EnsurePasswordIsChanged
{
    public const string ERROR_CODE = 'password_change_required';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password) {
            return response()->json([
                'message' => 'Debes cambiar tu contraseña.',
                'code' => self::ERROR_CODE,
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
