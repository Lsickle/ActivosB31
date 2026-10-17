<?php

use App\Actions\RegisterFailedLogin;
use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;

// RN-034 (FR-001..FR-003, D-003, D-004): 5 fallos seguidos bloquean la cuenta
// 15 minutos; la respuesta es siempre el mismo mensaje genérico.

beforeEach(function () {
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
});

function failLogin(string $login, int $times = 1): void
{
    foreach (range(1, $times) as $_) {
        test()->postJson('/api/login', ['login' => $login, 'password' => 'clave-errada'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.login.0', 'Credenciales inválidas.');
    }
}

it('RN-034: usa 5 intentos y 15 minutos desde la configuración', function () {
    expect(config('auth.lockout.max_attempts'))->toBe(5)
        ->and(config('auth.lockout.minutes'))->toBe(15);
});

it('RN-034: el 5.º fallo seguido bloquea la cuenta 15 minutos y registra account_locked', function () {
    $this->freezeTime();
    $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);

    failLogin('bombero', 4);
    expect($user->fresh())->failed_login_attempts->toBe(4)->locked_until->toBeNull();

    failLogin('bombero');

    $user->refresh();
    expect($user->failed_login_attempts)->toBe(5)
        ->and($user->locked_until->toIso8601String())->toBe(now()->addMinutes(15)->toIso8601String());

    $locked = SecurityEvent::query()->where('event', SecurityEventType::AccountLocked)->sole();
    expect($locked->target_user_id)->toBe($user->id)
        ->and($locked->actor_id)->toBeNull()
        ->and($locked->metadata['until'])->toBe(now()->addMinutes(15)->toIso8601String());
});

it('RN-034 / FR-002: una cuenta bloqueada rechaza la contraseña correcta con el mismo mensaje', function () {
    User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);
    failLogin('bombero', 5);

    $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'Credenciales inválidas.',
            'errors' => ['login' => ['Credenciales inválidas.']],
        ]);

    $this->assertGuest('web');
    expect(SecurityEvent::query()->where('event', SecurityEventType::LoginFailed)->latest('id')->first()->metadata)
        ->toBe(['reason' => 'locked']);
});

it('RN-034: el bloqueo aplica también al login con token (móvil)', function () {
    User::factory()->create(['username' => 'movil', 'password' => 'secreto-123']);
    failLogin('movil', 5);

    $this->postJson('/api/login', ['login' => 'movil', 'password' => 'secreto-123', 'device_name' => 'android'])
        ->assertUnprocessable()
        ->assertJsonMissingPath('token');
});

it('RN-034: fallos durante el bloqueo no lo extienden ni incrementan el contador', function () {
    $this->freezeTime();
    $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);
    failLogin('bombero', 5);
    $until = $user->fresh()->locked_until;

    $this->travel(5)->minutes();
    failLogin('bombero', 2);

    expect($user->fresh())
        ->failed_login_attempts->toBe(5)
        ->locked_until->equalTo($until)->toBeTrue();
    expect(SecurityEvent::query()->where('event', SecurityEventType::AccountLocked)->count())->toBe(1);
});

it('RN-034: vencido el bloqueo, la contraseña correcta entra y el contador vuelve a cero', function () {
    $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);
    failLogin('bombero', 5);
    expect($user->fresh()->locked_until)->not->toBeNull();

    $this->travel(16)->minutes();

    $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])->assertOk();

    expect($user->fresh())->failed_login_attempts->toBe(0)->locked_until->toBeNull();
});

it('RN-034 / D-003: vencido el bloqueo, un nuevo fallo vuelve a contar desde 1', function () {
    $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);
    failLogin('bombero', 5);

    $this->travel(16)->minutes();
    failLogin('bombero');

    expect($user->fresh())->failed_login_attempts->toBe(1)->locked_until->toBeNull();
});

it('FR-003: un ingreso exitoso reinicia el contador (los fallos deben ser seguidos)', function () {
    $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);
    failLogin('bombero', 3);

    $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])->assertOk();
    expect($user->fresh()->failed_login_attempts)->toBe(0);

    $this->app['auth']->forgetGuards();
    failLogin('bombero', 4);

    expect($user->fresh())->failed_login_attempts->toBe(4)->locked_until->toBeNull();
});

it('RN-034: los fallos contra una cuenta inactiva cuentan igual', function () {
    $user = User::factory()->inactive()->create(['username' => 'retirado', 'password' => 'secreto-123']);

    failLogin('retirado', 4);
    $this->postJson('/api/login', ['login' => 'retirado', 'password' => 'secreto-123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.login.0', 'Credenciales inválidas.');

    expect($user->fresh())->failed_login_attempts->toBe(5)->locked_until->not->toBeNull();
});

it('RN-034: los fallos con un usuario inexistente no bloquean nada', function () {
    $user = User::factory()->create(['username' => 'bombero']);

    failLogin('fantasma', 6);

    expect($user->fresh())->failed_login_attempts->toBe(0)->locked_until->toBeNull();
    expect(SecurityEvent::query()->where('event', SecurityEventType::LoginFailed)->whereNull('target_user_id')->count())->toBe(6);
});

it('D-003: el incremento es atómico aunque el modelo en memoria esté desactualizado', function () {
    $user = User::factory()->create();
    $staleA = User::query()->find($user->id);
    $staleB = User::query()->find($user->id);

    app(RegisterFailedLogin::class)->handle($staleA);
    app(RegisterFailedLogin::class)->handle($staleB);

    expect($user->fresh()->failed_login_attempts)->toBe(2);
});

it('D-003: dos fallos concurrentes en el umbral bloquean una sola vez', function () {
    $user = User::factory()->create(['failed_login_attempts' => 4]);
    $staleA = User::query()->find($user->id);
    $staleB = User::query()->find($user->id);

    $first = app(RegisterFailedLogin::class)->handle($staleA);
    $second = app(RegisterFailedLogin::class)->handle($staleB);

    expect($first)->not->toBeNull()
        ->and($second)->toBeNull()
        ->and($user->fresh()->failed_login_attempts)->toBe(5);
});
