<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Corta cualquier request autenticado de una cuenta desactivada, aunque su
 * sesión web o token Bearer se haya emitido antes de la desactivación. Se
 * aplica a todo el grupo `auth:sanctum` de routes/api.php.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            abort(403, 'Cuenta inactiva.');
        }

        return $next($request);
    }
}
