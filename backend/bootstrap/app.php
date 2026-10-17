<?php

use App\Http\Middleware\EnsurePasswordIsChanged;
use App\Http\Middleware\EnsureUserIsActive;
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
        // Render y Vercel terminan TLS en su proxy: confiar en X-Forwarded-*.
        $middleware->trustProxies(at: '*');

        // Sanctum: sesión por cookie para el SPA web (dominios en
        // SANCTUM_STATEFUL_DOMAINS).
        $middleware->statefulApi();

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'password.changed' => EnsurePasswordIsChanged::class,
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
