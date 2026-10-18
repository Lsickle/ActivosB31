<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Pendiente de seguridad 1 (CLAUDE.md): detrás del rewrite de Vercel, Laravel
 * ve la IP de Vercel. Con el secreto compartido (X-Proxy-Secret) la petición
 * viene de nuestro proxy y X-Real-IP es la IP real del cliente; sin él, la
 * IP se resuelve como antes (TrustProxies con REMOTE_ADDR).
 */
beforeEach(function () {
    Route::get('/__test/client-ip', fn (Request $request) => response()->json([
        'ip' => $request->ip(),
        'secret_header' => $request->headers->has('X-Proxy-Secret'),
        'secret_server' => $request->server->has('HTTP_X_PROXY_SECRET'),
    ]));

    config([
        'services.vercel_proxy.secret' => 'secreto-de-prueba',
        'services.vercel_proxy.required' => false,
        'services.vercel_proxy.previous_secret' => '',
    ]);
});

afterEach(function () {
    unset($_SERVER['HTTP_X_PROXY_SECRET']);
});

it('con el secreto válido toma la IP de X-Real-IP y elimina X-Proxy-Secret', function () {
    $this->withHeaders([
        'X-Proxy-Secret' => 'secreto-de-prueba',
        'X-Real-IP' => '203.0.113.7',
        'X-Forwarded-For' => '198.51.100.1',
    ])->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.7', 'secret_header' => false, 'secret_server' => false]);
});

it('con el secreto válido acepta una IPv6 en X-Real-IP', function () {
    $this->withHeaders(['X-Proxy-Secret' => 'secreto-de-prueba', 'X-Real-IP' => '2001:db8::1'])
        ->getJson('/__test/client-ip')
        ->assertJson(['ip' => '2001:db8::1']);
});

it('con un secreto inválido ignora X-Real-IP y elimina X-Proxy-Secret', function () {
    $this->withHeaders([
        'X-Proxy-Secret' => 'otro-secreto',
        'X-Real-IP' => '203.0.113.7',
    ])->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1', 'secret_header' => false, 'secret_server' => false]);
});

it('sin X-Proxy-Secret ignora X-Real-IP (no se puede falsificar la IP)', function () {
    $this->withHeaders(['X-Real-IP' => '203.0.113.7'])
        ->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1']);
});

it('con el secreto válido y una X-Real-IP inválida conserva la IP de siempre', function (string $realIp) {
    $this->withHeaders(['X-Proxy-Secret' => 'secreto-de-prueba', 'X-Real-IP' => $realIp])
        ->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1', 'secret_header' => false]);
})->with([
    'texto' => ['no-es-una-ip'],
    'lista' => ['203.0.113.7, 198.51.100.1'],
    'vacía' => [''],
]);

it('sin secreto configurado nunca confía en X-Real-IP, aunque llegue X-Proxy-Secret vacío', function () {
    config(['services.vercel_proxy.secret' => '']);

    $this->withHeaders(['X-Proxy-Secret' => '', 'X-Real-IP' => '203.0.113.7'])
        ->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '127.0.0.1', 'secret_header' => false]);
});

it('elimina el secreto también de $_SERVER (no llega a logs ni a reportes de error)', function () {
    $_SERVER['HTTP_X_PROXY_SECRET'] = 'secreto-de-prueba';

    $this->withHeaders(['X-Proxy-Secret' => 'secreto-de-prueba', 'X-Real-IP' => '203.0.113.7'])
        ->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '203.0.113.7']);

    expect(array_key_exists('HTTP_X_PROXY_SECRET', $_SERVER))->toBeFalse();
});

it('respeta TrustProxies: sin secreto, la IP sale del último salto de X-Forwarded-For', function () {
    $this->withHeaders(['X-Forwarded-For' => '198.51.100.1, 198.51.100.2'])
        ->getJson('/__test/client-ip')
        ->assertOk()
        ->assertJson(['ip' => '198.51.100.2']);
});

describe('rotación con VERCEL_PROXY_SECRET_PREVIOUS', function () {
    beforeEach(function () {
        config([
            'services.vercel_proxy.secret' => 'secreto-nuevo',
            'services.vercel_proxy.previous_secret' => 'secreto-anterior',
        ]);
    });

    it('acepta el secreto nuevo', function () {
        $this->withHeaders(['X-Proxy-Secret' => 'secreto-nuevo', 'X-Real-IP' => '203.0.113.7'])
            ->getJson('/__test/client-ip')
            ->assertJson(['ip' => '203.0.113.7', 'secret_header' => false]);
    });

    it('acepta el secreto anterior mientras siga configurado', function () {
        $this->withHeaders(['X-Proxy-Secret' => 'secreto-anterior', 'X-Real-IP' => '203.0.113.7'])
            ->getJson('/__test/client-ip')
            ->assertJson(['ip' => '203.0.113.7', 'secret_header' => false]);
    });

    it('rechaza cualquier otro secreto', function () {
        config(['services.vercel_proxy.required' => true]);

        $this->withHeaders(['X-Proxy-Secret' => 'otro-secreto'])
            ->getJson('/__test/client-ip')
            ->assertForbidden();
    });

    it('sin secreto anterior configurado, un X-Proxy-Secret vacío no pasa', function () {
        config([
            'services.vercel_proxy.previous_secret' => '',
            'services.vercel_proxy.required' => true,
        ]);

        $this->withHeaders(['X-Proxy-Secret' => ''])
            ->getJson('/__test/client-ip')
            ->assertForbidden();
    });

    it('el secreto anterior solo no basta si el actual está vacío', function () {
        config([
            'services.vercel_proxy.secret' => '',
            'services.vercel_proxy.required' => true,
        ]);

        $this->withHeaders(['X-Proxy-Secret' => 'secreto-anterior'])
            ->getJson('/__test/client-ip')
            ->assertForbidden();
    });
});

describe('VERCEL_PROXY_REQUIRED=true', function () {
    beforeEach(function () {
        config(['services.vercel_proxy.required' => true]);
    });

    it('rechaza con 403 una petición sin el secreto', function () {
        $this->getJson('/__test/client-ip')
            ->assertForbidden()
            ->assertJson(['message' => 'Acceso denegado.']);
    });

    it('rechaza con 403 una petición con un secreto inválido', function () {
        $this->withHeaders(['X-Proxy-Secret' => 'otro-secreto'])
            ->getJson('/__test/client-ip')
            ->assertForbidden();
    });

    it('acepta la petición con el secreto válido', function () {
        $this->withHeaders(['X-Proxy-Secret' => 'secreto-de-prueba', 'X-Real-IP' => '203.0.113.7'])
            ->getJson('/__test/client-ip')
            ->assertOk()
            ->assertJson(['ip' => '203.0.113.7']);
    });

    it('deja pasar /up sin el secreto (health check de Render)', function () {
        $this->get('/up')->assertOk();
    });

    it('falla cerrado si el secreto no está configurado', function () {
        config(['services.vercel_proxy.secret' => '']);

        $this->getJson('/__test/client-ip')->assertForbidden();
    });
});
