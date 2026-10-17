<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * RN-014, FR-009, FR-010, FR-011: cambio de contraseña propio.
 */

function insertSession(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'test',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);
}

it('RN-014: cambia la contraseña temporal y quita el cambio pendiente', function () {
    $user = actingAsRole('consulta', ['password' => 'temporal-123', 'must_change_password' => true]);

    $this->putJson('/api/user/password', [
        'current_password' => 'temporal-123',
        'password' => 'nueva-clave-456',
        'password_confirmation' => 'nueva-clave-456',
    ])->assertOk()->assertJsonPath('message', 'Contraseña actualizada.');

    $user->refresh();
    expect(Hash::check('nueva-clave-456', $user->password))->toBeTrue()
        ->and($user->must_change_password)->toBeFalse();

    $this->getJson('/api/users')->assertOk();
});

it('rechaza la contraseña actual incorrecta', function () {
    actingAsRole('consulta', ['password' => 'temporal-123']);

    $this->putJson('/api/user/password', [
        'current_password' => 'otra-clave-000',
        'password' => 'nueva-clave-456',
        'password_confirmation' => 'nueva-clave-456',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.current_password.0', 'La contraseña actual no es correcta.');
});

it('FR-010: exige al menos 10 caracteres con letras y números', function (string $password, string $message) {
    actingAsRole('consulta', ['password' => 'temporal-123']);

    $this->putJson('/api/user/password', [
        'current_password' => 'temporal-123',
        'password' => $password,
        'password_confirmation' => $password,
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password', fn (array $errors) => in_array($message, $errors, true));
})->with([
    'corta' => ['abc12', 'El campo contraseña debe tener al menos 10 caracteres.'],
    'sin números' => ['solamenteletras', 'El campo contraseña debe contener al menos un número.'],
    'sin letras' => ['1234567890', 'El campo contraseña debe contener al menos una letra.'],
]);

it('FR-010: la nueva contraseña debe ser distinta de la actual', function () {
    actingAsRole('consulta', ['password' => 'temporal-123', 'must_change_password' => true]);

    $this->putJson('/api/user/password', [
        'current_password' => 'temporal-123',
        'password' => 'temporal-123',
        'password_confirmation' => 'temporal-123',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'La nueva contraseña debe ser distinta de la actual.');
});

it('exige confirmar la nueva contraseña', function () {
    actingAsRole('consulta', ['password' => 'temporal-123']);

    $this->putJson('/api/user/password', [
        'current_password' => 'temporal-123',
        'password' => 'nueva-clave-456',
        'password_confirmation' => 'nueva-clave-789',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'La confirmación del campo contraseña no coincide.');
});

it('FR-011: cierra las demás sesiones web y conserva la actual', function () {
    config(['session.driver' => 'database']);
    $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);

    seedRbac();
    $user = User::factory()->create(['username' => 'bombero', 'password' => 'temporal-123']);
    $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'temporal-123'])->assertOk();
    $loginSessionId = DB::table('sessions')->where('user_id', $user->id)->value('id');
    insertSession($user, 'otra-sesion');
    $otherUser = User::factory()->create();
    insertSession($otherUser, 'sesion-ajena');
    $user->createToken('android');

    $this->putJson('/api/user/password', [
        'current_password' => 'temporal-123',
        'password' => 'nueva-clave-456',
        'password_confirmation' => 'nueva-clave-456',
    ])->assertOk();

    $remaining = DB::table('sessions')->where('user_id', $user->id)->pluck('id');
    expect($remaining)->toHaveCount(1)
        ->and($remaining->first())->not->toBe($loginSessionId)
        ->and($remaining->first())->not->toBe('otra-sesion')
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('id', 'sesion-ajena')->exists())->toBeTrue();

    $this->getJson('/api/user')->assertOk();
});

it('FR-011: con token Bearer revoca los demás tokens y conserva el actual', function () {
    seedRbac();
    $user = User::factory()->create(['password' => 'temporal-123']);
    $current = $user->createToken('actual')->plainTextToken;
    $user->createToken('otro');
    insertSession($user, 'sesion-web');

    $this->withToken($current)->putJson('/api/user/password', [
        'current_password' => 'temporal-123',
        'password' => 'nueva-clave-456',
        'password_confirmation' => 'nueva-clave-456',
    ])->assertOk();

    expect($user->tokens()->pluck('name')->all())->toBe(['actual'])
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('B-1: limita los intentos de cambio de contraseña a 6 por minuto por usuario', function () {
    actingAsRole('consulta', ['password' => 'temporal-123']);
    $attempt = fn () => $this->putJson('/api/user/password', [
        'current_password' => 'errada-000x',
        'password' => 'nueva-clave-456',
        'password_confirmation' => 'nueva-clave-456',
    ]);

    foreach (range(1, 6) as $_) {
        $attempt()->assertUnprocessable();
    }

    $attempt()->assertTooManyRequests();
});
