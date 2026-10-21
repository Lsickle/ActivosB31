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
 * producción las fotos van a B2 y las firmas a su propio bucket, decisiones
 * 44 y 45).
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

/**
 * Configuración de producción válida: discos b2 y b2_firmas con buckets y
 * llaves distintos (M1 de la revisión de seguridad de la migración a B2).
 *
 * @param  array<string, mixed>  $overrides
 */
function configureProductionStorage(array $overrides = []): void
{
    config([
        'filesystems.assets_disk' => 'b2',
        'filesystems.signatures_disk' => 'b2_firmas',
        'filesystems.disks.b2.bucket' => 'activosb31-fotos',
        'filesystems.disks.b2.key' => 'llave-fotos',
        'filesystems.disks.b2_firmas.bucket' => 'activosb31-firmas',
        'filesystems.disks.b2_firmas.key' => 'llave-firmas',
        ...$overrides,
    ]);
}

describe('M-4: discos de fotos y firmas en producción (decisiones 44 y 45)', function () {
    beforeEach(function () {
        configureProductionStorage();
    });

    afterEach(function () {
        $this->app['env'] = 'testing';
    });

    it('falla al arrancar en producción si ASSETS_DISK no es b2', function (string $disk) {
        config(['filesystems.assets_disk' => $disk]);
        $this->app['env'] = 'production';

        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, 'ASSETS_DISK=b2 ');
    })->with(['local', 'public', 's3', 'b2_firmas']);

    it('falla al arrancar en producción si SIGNATURES_DISK no es b2_firmas', function (string $disk) {
        config(['filesystems.signatures_disk' => $disk]);
        $this->app['env'] = 'production';

        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, 'SIGNATURES_DISK=b2_firmas');
    })->with(['local', 'public', 's3', 'b2']);

    it('M1: falla en producción si falta el bucket o la llave de fotos o de firmas', function (string $config, string $variable) {
        configureProductionStorage([$config => null]);
        $this->app['env'] = 'production';

        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, $variable);
    })->with([
        'B2_BUCKET' => ['filesystems.disks.b2.bucket', 'B2_BUCKET'],
        'B2_ACCESS_KEY_ID' => ['filesystems.disks.b2.key', 'B2_ACCESS_KEY_ID'],
        'B2_SIGNATURES_BUCKET' => ['filesystems.disks.b2_firmas.bucket', 'B2_SIGNATURES_BUCKET'],
        'B2_SIGNATURES_ACCESS_KEY_ID' => ['filesystems.disks.b2_firmas.key', 'B2_SIGNATURES_ACCESS_KEY_ID'],
    ]);

    it('M1: una cadena vacía cuenta como variable faltante', function () {
        configureProductionStorage(['filesystems.disks.b2_firmas.key' => '']);
        $this->app['env'] = 'production';

        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, 'B2_SIGNATURES_ACCESS_KEY_ID');
    });

    it('M1: falla en producción si firmas y fotos comparten bucket', function () {
        configureProductionStorage(['filesystems.disks.b2_firmas.bucket' => 'activosb31-fotos']);
        $this->app['env'] = 'production';

        expect(fn () => (new AppServiceProvider($this->app))->boot())
            ->toThrow(RuntimeException::class, 'B2_SIGNATURES_BUCKET debe ser distinto de B2_BUCKET');
    });

    it('M1: falla en producción si firmas y fotos comparten llave, sin mostrar la llave', function () {
        configureProductionStorage([
            'filesystems.disks.b2.key' => 'llave-secreta-compartida',
            'filesystems.disks.b2_firmas.key' => 'llave-secreta-compartida',
        ]);
        $this->app['env'] = 'production';

        try {
            (new AppServiceProvider($this->app))->boot();
            $this->fail('Debió fallar al arrancar.');
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toContain('B2_SIGNATURES_ACCESS_KEY_ID debe ser distinto de B2_ACCESS_KEY_ID')
                ->not->toContain('llave-secreta-compartida');
        }
    });

    it('arranca en producción con b2 y b2_firmas separados, y fuera de producción con cualquier disco', function () {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        config([
            'filesystems.assets_disk' => 'local', 'filesystems.signatures_disk' => 'local',
            'filesystems.disks.b2.bucket' => null, 'filesystems.disks.b2_firmas.bucket' => null,
        ]);
        $this->app['env'] = 'local';
        (new AppServiceProvider($this->app))->boot();

        expect(true)->toBeTrue();
    });
});

describe('Decisión 45: disco de firmas', function () {
    it('SIGNATURES_DISK hereda ASSETS_DISK si no se define', function () {
        $app = bootIsolatedApplication(['ASSETS_DISK' => 'public']);

        expect($app['config']->get('filesystems.signatures_disk'))->toBe('public');
    });

    it('SIGNATURES_DISK define el disco de firmas por separado', function () {
        $app = bootIsolatedApplication(['ASSETS_DISK' => 'local', 'SIGNATURES_DISK' => 'public']);

        expect($app['config']->get('filesystems.assets_disk'))->toBe('local')
            ->and($app['config']->get('filesystems.signatures_disk'))->toBe('public');
    });

    it('b2_firmas usa su propio bucket y su propia llave, con la región y el endpoint de B2', function () {
        $app = bootIsolatedApplication([
            'B2_ACCESS_KEY_ID' => 'llave-fotos', 'B2_SECRET_ACCESS_KEY' => 'secreto-fotos', 'B2_BUCKET' => 'activosb31-fotos',
            'B2_SIGNATURES_ACCESS_KEY_ID' => 'llave-firmas', 'B2_SIGNATURES_SECRET_ACCESS_KEY' => 'secreto-firmas',
            'B2_SIGNATURES_BUCKET' => 'activosb31-firmas',
            'B2_REGION' => 'us-east-005', 'B2_ENDPOINT' => 'https://s3.us-east-005.backblazeb2.com',
        ]);
        $disks = $app['config']->get('filesystems.disks');

        expect($disks['b2'])->toMatchArray([
            'key' => 'llave-fotos', 'secret' => 'secreto-fotos', 'bucket' => 'activosb31-fotos',
            'region' => 'us-east-005', 'endpoint' => 'https://s3.us-east-005.backblazeb2.com',
        ])->and($disks['b2_firmas'])->toMatchArray([
            'key' => 'llave-firmas', 'secret' => 'secreto-firmas', 'bucket' => 'activosb31-firmas',
            'region' => 'us-east-005', 'endpoint' => 'https://s3.us-east-005.backblazeb2.com',
        ]);
    });
});
