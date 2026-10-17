<?php

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;

// RN-034 / A-2 (revisión de seguridad de 003): desbloqueo por consola para
// cuando el único Administrador queda bloqueado y nadie puede usar la API.

function unlockEvents(): SecurityEvent
{
    return SecurityEvent::query()->where('event', SecurityEventType::AccountUnlocked)->sole();
}

it('A-2: desbloquea por usuario sin distinguir mayúsculas y lo registra como canal cli sin actor', function () {
    $user = User::factory()->create([
        'username' => 'admin',
        'failed_login_attempts' => 5,
        'locked_until' => now()->addMinutes(10),
    ]);

    $this->artisan('security:unlock', ['login' => '  ADMIN '])
        ->expectsOutputToContain('desbloqueada')
        ->assertSuccessful();

    expect($user->fresh())->failed_login_attempts->toBe(0)->locked_until->toBeNull();
    expect(unlockEvents())
        ->actor_id->toBeNull()
        ->target_user_id->toBe($user->id)
        ->metadata->toBe(['channel' => 'cli'])
        ->ip_address->toBeNull()
        ->user_agent->toBeNull();
});

it('A-2: desbloquea por correo', function () {
    $user = User::factory()->create([
        'email' => 'jefe@bomberos.test',
        'failed_login_attempts' => 5,
        'locked_until' => now()->addMinutes(10),
    ]);

    $this->artisan('security:unlock', ['login' => 'Jefe@Bomberos.test'])->assertSuccessful();

    expect($user->fresh()->locked_until)->toBeNull();
});

it('A-2: falla con código distinto de cero si la cuenta no existe', function () {
    $this->artisan('security:unlock', ['login' => 'fantasma'])
        ->expectsOutputToContain('No existe')
        ->assertFailed();

    expect(SecurityEvent::query()->count())->toBe(0);
});

it('A-2: avisa sin registrar evento si la cuenta no estaba bloqueada', function () {
    $user = User::factory()->create(['username' => 'bombero', 'failed_login_attempts' => 2]);

    $this->artisan('security:unlock', ['login' => 'bombero'])
        ->expectsOutputToContain('no está bloqueada')
        ->assertSuccessful();

    expect($user->fresh()->failed_login_attempts)->toBe(2)
        ->and(SecurityEvent::query()->count())->toBe(0);
});

it('A-2: tras el desbloqueo por consola la cuenta entra de inmediato', function () {
    User::factory()->create([
        'username' => 'bombero',
        'password' => 'secreto-123',
        'failed_login_attempts' => 5,
        'locked_until' => now()->addMinutes(10),
    ]);

    $this->artisan('security:unlock', ['login' => 'bombero'])->assertSuccessful();

    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0])
        ->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])
        ->assertOk();
});
