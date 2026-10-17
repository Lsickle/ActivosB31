<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureProductionAssetsDisk();
        $this->configureRateLimiting();
        $this->configureAuthorization();
        $this->configureRoutePatterns();
    }

    /**
     * M-4 (revisión de seguridad de 005): en producción las fotos de activos
     * van al bucket privado de R2 (docs/infraestructura.md). El disco local
     * de Render es efímero y "public" las expondría sin URL firmada.
     *
     * Se omite en package:discover: `composer install` lo ejecuta al
     * construir la imagen, sin las variables de Render y con APP_ENV
     * cayendo en "production" (config/app.php).
     */
    private function ensureProductionAssetsDisk(): void
    {
        $disk = config('filesystems.assets_disk');

        if ($this->app->isProduction() && $disk !== 'r2' && ! $this->app->runningConsoleCommand('package:discover')) {
            throw new RuntimeException('En producción las fotos de activos deben guardarse en R2: defina ASSETS_DISK=r2 (valor actual: '.var_export($disk, true).').');
        }
    }

    /**
     * B-4 (revisión de seguridad de 005): los ids de activo y de plan solo
     * admiten dígitos; cualquier otro valor da 404 sin llegar a la consulta.
     */
    private function configureRoutePatterns(): void
    {
        Route::pattern('asset', '[0-9]+');
        Route::pattern('plan', '[0-9]+');
    }

    /**
     * D-003: una habilidad por cada permiso del catálogo (RN-009), evaluada
     * contra los permisos memorizados del usuario. Sin Gate::before (M-5): el
     * Administrador recibe los 13 permisos desde User::permissionCodes()
     * (RN-010), y las Policies y reglas de negocio se evalúan también para él.
     */
    private function configureAuthorization(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPermission($permission));
        }
    }

    private function configureRateLimiting(): void
    {
        // Dos límites combinados: por IP + cuenta (fuerza bruta contra una
        // cuenta) y por IP sola (password spraying contra muchas cuentas).
        RateLimiter::for('login', function (Request $request) {
            $normalizedLogin = Str::lower(trim((string) $request->input('login')));

            return [
                Limit::perMinute(10)->by($request->ip().'|'.$normalizedLogin),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        // B-1: frena la adivinación de la contraseña actual con una sesión ya
        // abierta.
        RateLimiter::for('password-change', function (Request $request) {
            return Limit::perMinute(6)->by('password-change|'.($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
    }
}
