<?php

use App\Providers\AppServiceProvider;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\BootProviders;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables;
use Illuminate\Foundation\Bootstrap\RegisterFacades;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Route;

/**
 * 005 — Revisión de seguridad del almacenamiento de fotos (FR-008):
 * M-3 (el disco local no sirve /storage salvo que se pida) y M-4 (en
 * producción las fotos van a R2).
 */

/**
 * Arranca una aplicación aparte con variables de entorno distintas y la
 * devuelve; restaura las variables y las fachadas de la aplicación del test.
 *
 * @param  array<string, string>  $env
 */
function bootIsolatedApplication(array $env): Application
{
    $testApp = app();
    $previous = [];

    foreach ($env as $name => $value) {
        $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
        $_ENV[$name] = $_SERVER[$name] = $value;
        putenv("{$name}={$value}");
    }

    try {
        Facade::clearResolvedInstances();
        $app = require base_path('bootstrap/app.php');
        $app->bootstrapWith([
            LoadEnvironmentVariables::class,
            LoadConfiguration::class,
            RegisterFacades::class,
            RegisterProviders::class,
            BootProviders::class,
        ]);

        return $app;
    } finally {
        foreach ($previous as $name => [$envValue, $serverValue, $putenvValue]) {
            if ($envValue === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $envValue;
            }
            if ($serverValue === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $serverValue;
            }
            putenv($putenvValue === false ? $name : "{$name}={$putenvValue}");
        }

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($testApp);
        Container::setInstance($testApp);
    }
}

it('M-3: el disco local sirve /storage solo con LOCAL_DISK_SERVE=true', function () {
    // phpunit.xml lo activa para los tests de URL temporal (US1-6).
    expect(config('filesystems.disks.local.serve'))->toBeTrue()
        ->and(Route::has('storage.local'))->toBeTrue();

    $app = bootIsolatedApplication(['LOCAL_DISK_SERVE' => 'false']);

    expect($app['config']->get('filesystems.disks.local.serve'))->toBeFalse()
        ->and($app['router']->has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local'))->toBeTrue();
});

describe('M-4: disco de fotos en producción', function () {
    afterEach(function () {
        $this->app['env'] = 'testing';
    });

    it('falla al arrancar en producción si ASSETS_DISK no es r2', function (string $disk) {
        config(['filesystems.assets_disk' => $disk]);
        $this->app['env'] = 'production';

        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, 'ASSETS_DISK=r2');
    })->with(['local', 'public', 's3']);

    it('arranca en producción con r2 y fuera de producción con cualquier disco', function () {
        config(['filesystems.assets_disk' => 'r2']);
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        config(['filesystems.assets_disk' => 'local']);
        $this->app['env'] = 'local';
        (new AppServiceProvider($this->app))->boot();

        expect(true)->toBeTrue();
    });
});
