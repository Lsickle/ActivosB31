<?php

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

// Sanctum solo abre sesión por cookie si la petición viene de un dominio
// stateful (SANCTUM_STATEFUL_DOMAINS), igual que el SPA real.
beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
});

it('responde el health check', function () {
    $this->get('/up')->assertOk();
});

it('inicia sesión web por cookie con el username', function () {
    $user = User::factory()->create(['username' => 'bombero1', 'password' => 'secreto-123']);

    $this->postJson('/api/login', ['login' => 'bombero1', 'password' => 'secreto-123'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonMissingPath('token');

    $this->assertAuthenticatedAs($user, 'web');
    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('acepta el email sin importar mayúsculas', function () {
    User::factory()->create(['email' => 'jefe@b31.co', 'password' => 'secreto-123']);

    $this->postJson('/api/login', ['login' => 'JEFE@B31.co', 'password' => 'secreto-123'])
        ->assertOk();
});

it('emite un token Bearer cuando se envía device_name', function () {
    User::factory()->create(['username' => 'movil', 'password' => 'secreto-123']);

    $response = $this->postJson('/api/login', [
        'login' => 'movil',
        'password' => 'secreto-123',
        'device_name' => 'android',
    ])->assertOk()->assertJsonStructure(['user', 'token']);

    expect(PersonalAccessToken::findToken($response->json('token'))?->expires_at)->not->toBeNull();
});

it('rechaza credenciales inválidas con el mismo mensaje', function (string $login) {
    User::factory()->create(['username' => 'real', 'password' => 'secreto-123']);

    $this->postJson('/api/login', ['login' => $login, 'password' => 'otra-clave'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.login.0', 'Credenciales inválidas.');

    $this->assertGuest('web');
})->with(['usuario existente' => 'real', 'usuario inexistente' => 'fantasma']);

it('rechaza el login de una cuenta inactiva', function () {
    User::factory()->inactive()->create(['username' => 'retirado', 'password' => 'secreto-123']);

    // Mismo mensaje que credenciales inválidas: no confirma la contraseña.
    $this->postJson('/api/login', ['login' => 'retirado', 'password' => 'secreto-123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.login.0', 'Credenciales inválidas.');
});

it('limita la tasa de intentos de login', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/login', ['login' => 'x', 'password' => 'y']);
    }

    $this->postJson('/api/login', ['login' => 'x', 'password' => 'y'])->assertTooManyRequests();
});
