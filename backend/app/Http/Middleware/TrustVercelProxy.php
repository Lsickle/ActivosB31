<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pendiente de seguridad 1 (CLAUDE.md, A-1 de la revisión de 003): detrás del
 * rewrite de Vercel, la conexión que llega a Render es la de Vercel y
 * $request->ip() sería la IP de Vercel para todos los usuarios (rate limiting
 * compartido, bitácora sin IP real).
 *
 * vercel.json agrega X-Proxy-Secret con VERCEL_PROXY_SECRET. Solo si coincide
 * (hash_equals) se toma X-Real-IP (que Vercel fija con la IP del cliente) y se
 * pone como X-Forwarded-For, que TrustProxies (REMOTE_ADDR, el proxy de
 * Render) ya resuelve. Sin el secreto, la IP se resuelve como antes. El
 * secreto nunca sigue hacia la aplicación (ni a logs ni a la bitácora).
 *
 * Se registra con `prepend` (antes que TrustProxies y que cualquier otro
 * middleware), para que el secreto desaparezca de la petición y de $_SERVER
 * (que leen los reportes de error) lo antes posible. La IP se resuelve de
 * forma perezosa en $request->ip(), así que el orden no cambia el resultado
 * de TrustProxies.
 *
 * Rotación (B2 de la revisión del despliegue): durante el cambio de secreto
 * se acepta también VERCEL_PROXY_SECRET_PREVIOUS (solo si el actual está
 * definido). Ver docs/despliegue.md.
 *
 * VERCEL_PROXY_REQUIRED=true rechaza con 403 todo lo que no venga del proxy,
 * salvo /up (health check de Render). Por defecto false: decisión pendiente
 * del usuario.
 */
class TrustVercelProxy
{
    public const string SECRET_HEADER = 'X-Proxy-Secret';

    public const string REAL_IP_HEADER = 'X-Real-IP';

    public function handle(Request $request, Closure $next): Response
    {
        $fromProxy = $this->hasValidSecret($request);

        $request->headers->remove(self::SECRET_HEADER);
        $request->server->remove('HTTP_X_PROXY_SECRET');
        unset($_SERVER['HTTP_X_PROXY_SECRET']);

        if ($fromProxy) {
            $realIp = $this->realIp($request);

            if ($realIp !== null) {
                $request->headers->set('X-Forwarded-For', $realIp);
                $request->server->set('HTTP_X_FORWARDED_FOR', $realIp);
            }
        } elseif (config('services.vercel_proxy.required') && ! $request->is('up')) {
            return response()->json(['message' => 'Acceso denegado.'], 403);
        }

        return $next($request);
    }

    private function hasValidSecret(Request $request): bool
    {
        $secret = (string) config('services.vercel_proxy.secret');
        $previous = (string) config('services.vercel_proxy.previous_secret');
        $received = $request->headers->get(self::SECRET_HEADER);

        if ($secret === '' || ! is_string($received) || $received === '') {
            return false;
        }

        // Se comparan ambos siempre (sin cortocircuito) para no revelar por
        // tiempo cuál de los dos coincidió.
        $matchesCurrent = hash_equals($secret, $received);
        $matchesPrevious = $previous !== '' && hash_equals($previous, $received);

        return $matchesCurrent || $matchesPrevious;
    }

    private function realIp(Request $request): ?string
    {
        $value = trim((string) $request->headers->get(self::REAL_IP_HEADER));

        return filter_var($value, FILTER_VALIDATE_IP) !== false ? $value : null;
    }
}
