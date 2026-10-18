<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\LimitRequestBody;
use App\Http\Middleware\RequireJsonBody;
use App\Http\Middleware\TrustVercelProxy;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // A-1 (revisión de seguridad de 003, D-009). SUPUESTO POR VERIFICAR en el
        // primer despliegue: Render termina TLS en su proxy y
        // agrega la IP real al final de X-Forwarded-For. Se confía solo en el
        // salto inmediato (REMOTE_ADDR): la IP del cliente es el último valor
        // que agregó ese proxy, no el primero, que el cliente puede inventar.
        // Con '*' cualquiera falsificaba la IP de la bitácora y evadía el
        // rate limiting por IP. Si se agrega otro proxy delante (p. ej. el
        // rewrite de Vercel), revisar cuántos saltos hay que confiar.
        $middleware->trustProxies(at: 'REMOTE_ADDR');

        // Pendiente de seguridad 1: con el rewrite de Vercel delante, la IP
        // real llega en X-Real-IP y solo se acepta con X-Proxy-Secret
        // (VERCEL_PROXY_SECRET). Va al inicio del stack global (A4 de la
        // revisión del despliegue): el secreto se borra antes que cualquier
        // otro middleware lo vea; TrustProxies resuelve la IP después, al
        // llamar a $request->ip(). Por verificar en el primer deploy
        // (docs/despliegue.md).
        $middleware->prepend(TrustVercelProxy::class);

        // Sanctum: sesión por cookie para el SPA web (dominios en
        // SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
            'body.limit' => LimitRequestBody::class,
            'json.body' => RequireJsonBody::class,
        ]);

        // API pura: no existe una ruta web 'login'. Sin esto, una petición no
        // autenticada sin Accept: application/json intentaría redirigir a
        // route('login') y fallaría en vez de responder 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $rendersJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($rendersJson);

        // Mensajes genéricos del framework en español. Los mensajes propios
        // (p. ej. "Cuenta inactiva.") se conservan.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($rendersJson) {
            return $rendersJson($request)
                ? response()->json(['message' => 'No autenticado.'], 401)
                : null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($rendersJson) {
            $isGeneric = in_array($e->getMessage(), ['', 'This action is unauthorized.'], true);

            return $isGeneric && $rendersJson($request)
                ? response()->json(['message' => 'No tienes permiso para realizar esta acción.'], 403)
                : null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($rendersJson) {
            return $rendersJson($request)
                ? response()->json(['message' => 'Recurso no encontrado.'], 404)
                : null;
        });
    })->create();
