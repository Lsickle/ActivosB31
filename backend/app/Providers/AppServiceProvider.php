<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        $this->configureRateLimiting();
        $this->configureAuthorization();
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
