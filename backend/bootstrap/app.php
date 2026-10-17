<?php

use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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

        $middleware->alias(['active' => EnsureUserIsActive::class]);

        // API pura: no existe una ruta web 'login'. Sin esto, una petición no
        // autenticada sin Accept: application/json intentaría redirigir a
        // route('login') y fallaría en vez de responder 401.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
