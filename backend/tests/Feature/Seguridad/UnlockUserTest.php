<?php

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;

// RN-034 / FR-004 (D-007): el Administrador (usuarios.gestionar, RN-019)
// desbloquea una cuenta antes de que venza el plazo.

function lockedUser(array $attributes = []): User
{
    return User::factory()->create([
        'failed_login_attempts' => 5,
        'locked_until' => now()->addMinutes(10),
        ...$attributes,
    ]);
}

it('RN-034: el Administrador desbloquea una cuenta y queda registrado quién lo hizo', function () {
    $admin = actingAsRole('admin');
    $user = lockedUser();

    $this->postJson("/api/users/{$user->id}/unlock")
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.locked_until', null);

    expect($user->fresh())->failed_login_attempts->toBe(0)->locked_until->toBeNull();

    $event = SecurityEvent::query()->where('event', SecurityEventType::AccountUnlocked)->sole();
    expect($event->actor_id)->toBe($admin->id)
        ->and($event->target_user_id)->toBe($user->id);
});

it('RN-034: tras el desbloqueo la cuenta entra de inmediato', function () {
    actingAsRole('admin');
    lockedUser(['username' => 'bombero', 'password' => 'secreto-123']);

    $this->postJson('/api/users/'.User::query()->where('username', 'bombero')->value('id').'/unlock')->assertOk();

    $this->app['auth']->forgetGuards();
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0])
        ->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])
        ->assertOk();
});

it('RN-034: desbloquear una cuenta no bloqueada no hace nada ni registra evento', function () {
    actingAsRole('admin');
    $user = User::factory()->create(['failed_login_attempts' => 2]);

    $this->postJson("/api/users/{$user->id}/unlock")->assertOk();

    expect($user->fresh()->failed_login_attempts)->toBe(2)
        ->and(SecurityEvent::query()->where('event', SecurityEventType::AccountUnlocked)->exists())->toBeFalse();
});

it('RN-019: sin usuarios.gestionar no se puede desbloquear', function (string $role) {
    actingAsRole($role);
    $user = lockedUser();

    $this->postJson("/api/users/{$user->id}/unlock")->assertForbidden();

    expect($user->fresh()->locked_until)->not->toBeNull();
})->with(['almacenista', 'consulta']);

it('exige autenticación para desbloquear', function () {
    seedRbac();
    $user = lockedUser();

    $this->postJson("/api/users/{$user->id}/unlock")->assertUnauthorized();
});

it('FR-004: el listado de usuarios muestra locked_until solo mientras el bloqueo esté vigente', function () {
    actingAsRole('admin');
    $locked = lockedUser(['name' => 'Ana Bloqueada']);
    $expired = User::factory()->create(['name' => 'Beto Vencido', 'failed_login_attempts' => 5, 'locked_until' => now()->subMinute()]);

    $response = $this->getJson('/api/users?per_page=100')->assertOk();
    $byId = collect($response->json('data'))->keyBy('id');

    expect($byId[$locked->id]['locked_until'])->not->toBeNull()
        ->and($byId[$expired->id]['locked_until'])->toBeNull()
        ->and($byId[$locked->id])->not->toHaveKey('failed_login_attempts');
});
