<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }
}
