<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Revisión de seguridad de 007: tope de cuerpo por ruta, más estricto que
 * post_max_size (8 MB, pensado para las fotos de activos). Uso:
 * `body.limit:1024` (kilobytes). Se mide por Content-Length y, si no viene
 * (cuerpo chunked), por el contenido leído.
 */
class LimitRequestBody
{
    public function handle(Request $request, Closure $next, string $kilobytes = '1024'): Response
    {
        $maxBytes = (int) $kilobytes * 1024;
        $declared = $request->headers->get('Content-Length');
        $size = is_numeric($declared) ? (int) $declared : strlen((string) $request->getContent());

        if ($size > $maxBytes) {
            return response()->json([
                'message' => 'La petición es demasiado grande (máximo '.$this->humanSize($maxBytes).').',
            ], 413);
        }

        return $next($request);
    }

    private function humanSize(int $bytes): string
    {
        return $bytes % (1024 * 1024) === 0
            ? ($bytes / (1024 * 1024)).' MB'
            : (int) ceil($bytes / 1024).' KB';
    }
}
