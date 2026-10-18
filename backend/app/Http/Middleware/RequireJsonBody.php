<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A5 de la revisión de seguridad del despliegue: rutas que solo aceptan un
 * cuerpo JSON (POST /api/deliveries, con la firma en base64, RN-024). Un
 * formulario o multipart se rechaza con 415 antes de validar, para que PHP
 * no procese archivos subidos ni campos de formulario que la ruta no espera.
 * Uso: `json.body`, después de `body.limit`.
 */
class RequireJsonBody
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isJson()) {
            return response()->json([
                'message' => 'El cuerpo de la petición debe ser JSON.',
            ], 415);
        }

        return $next($request);
    }
}
