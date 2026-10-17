<?php

use App\Actions\EnsureAnotherActiveAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

function addSessionFor(User $user, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '10.0.0.1',
        'user_agent' => 'test', 'payload' => '', 'last_activity' => now()->timestamp,
    ]);
}

it('FR-011: desactivar un usuario cierra sus sesiones y revoca sus tokens', function () {
    actingAsRole('admin');
    $user = User::factory()->create();
    $token = $user->createToken('android')->plainTextToken;
    addSessionFor($user, 'sesion-1');

    $this->patchJson("/api/users/{$user->id}/status", ['is_active' => false])
        ->assertOk()
        ->assertJsonPath('data.is_active', false);

    expect($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/user')->assertUnauthorized();
});

it('reactiva un usuario', function () {
    actingAsRole('admin');
    $user = User::factory()->inactive()->create();

    $this->patchJson("/api/users/{$user->id}/status", ['is_active' => true])
        ->assertOk()
        ->assertJsonPath('data.is_active', true);
});

it('exige is_active booleano', function () {
    actingAsRole('admin');
    $user = User::factory()->create();

    $this->patchJson("/api/users/{$user->id}/status", [])->assertUnprocessable()->assertJsonValidationErrors('is_active');
});

it('FR-012: un usuario no puede desactivar su propia cuenta', function () {
    $actor = actingAsRole('admin');
    User::factory()->admin()->create();

    $this->patchJson("/api/users/{$actor->id}/status", ['is_active' => false])
        ->assertConflict()
        ->assertJsonPath('message', 'No puedes desactivar tu propia cuenta.');

    expect($actor->fresh()->is_active)->toBeTrue();
});

it('FR-012: la acción impide dejar la aplicación sin administrador activo', function () {
    // Por HTTP este caso solo es alcanzable por uno mismo (RN-019 deja la
    // gestión de usuarios al Administrador), y eso ya lo corta la regla de
    // no desactivarse; la acción se prueba directamente como segunda barrera.
    seedRbac();
    $onlyAdmin = User::factory()->admin()->create();
    User::factory()->admin()->inactive()->create();

    expect(fn () => DB::transaction(fn () => app(EnsureAnotherActiveAdmin::class)->handle($onlyAdmin)))
        ->toThrow(ConflictHttpException::class, 'Debe quedar al menos un Administrador activo.');

    $other = User::factory()->admin()->create();
    DB::transaction(fn () => app(EnsureAnotherActiveAdmin::class)->handle($onlyAdmin));
    expect($other->exists)->toBeTrue();
});

it('RN-018: un usuario no puede restablecer su propia contraseña desde la gestión de usuarios', function () {
    $actor = actingAsRole('admin', ['password' => 'actual-clave-1']);

    $this->putJson("/api/users/{$actor->id}/password", ['password' => 'temporal-456', 'password_confirmation' => 'temporal-456'])
        ->assertConflict()
        ->assertJsonPath('message', 'Para cambiar tu propia contraseña usa «Cambiar contraseña».');

    $actor->refresh();
    expect(Hash::check('actual-clave-1', $actor->password))->toBeTrue()
        ->and($actor->must_change_password)->toBeFalse();
});

it('B-2: revocar las sesiones rota el remember_token', function () {
    actingAsRole('admin');
    $user = User::factory()->create();
    $before = $user->remember_token;

    $this->patchJson("/api/users/{$user->id}/status", ['is_active' => false])->assertOk();

    expect($user->fresh()->remember_token)->not->toBe($before)->not->toBeEmpty();
});

it('FR-012: permite desactivar a un administrador si queda otro activo', function () {
    actingAsRole('admin');
    $other = User::factory()->admin()->create();

    $this->patchJson("/api/users/{$other->id}/status", ['is_active' => false])->assertOk();
});

it('RN-014: restablecer la contraseña obliga a cambiarla y cierra sesiones y tokens', function () {
    actingAsRole('admin');
    $user = User::factory()->create();
    $user->createToken('android');
    addSessionFor($user, 'sesion-1');

    $this->putJson("/api/users/{$user->id}/password", [
        'password' => 'temporal-456',
        'password_confirmation' => 'temporal-456',
    ])->assertOk()->assertJsonPath('message', 'Contraseña restablecida.');

    $user->refresh();
    expect($user->must_change_password)->toBeTrue()
        ->and(Hash::check('temporal-456', $user->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0)
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('FR-010: aplica la política de contraseña al restablecer', function () {
    actingAsRole('admin');
    $user = User::factory()->create();

    $this->putJson("/api/users/{$user->id}/password", ['password' => 'solamenteletras', 'password_confirmation' => 'solamenteletras'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('RN-009: niega cambiar estado o restablecer contraseña sin usuarios.gestionar', function () {
    actingAsRole('almacenista');
    $user = User::factory()->create();

    $this->patchJson("/api/users/{$user->id}/status", ['is_active' => false])->assertForbidden();
    $this->putJson("/api/users/{$user->id}/password", ['password' => 'temporal-456', 'password_confirmation' => 'temporal-456'])
        ->assertForbidden()
        ->assertJsonPath('message', 'No tienes permiso para realizar esta acción.');

    expect($user->fresh()->is_active)->toBeTrue();
});
