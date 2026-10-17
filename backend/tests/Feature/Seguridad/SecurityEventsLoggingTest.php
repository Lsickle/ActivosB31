<?php

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// A-1 / FR-005, FR-006, FR-009 (D-001): cada acción auditada deja exactamente
// un evento, dentro de su transacción; nunca contraseñas ni tokens.

function securityEventsOf(SecurityEventType $type): Collection
{
    return SecurityEvent::query()->where('event', $type)->orderBy('id')->get();
}

function soleSecurityEvent(SecurityEventType $type): SecurityEvent
{
    return SecurityEvent::query()->where('event', $type)->sole();
}

/**
 * FR-006: la fila completa (incluido metadata) no contiene el texto dado.
 */
function expectNoSecretInEvents(string $secret): void
{
    $rows = json_encode(SecurityEvent::query()->get()->map->getAttributes()->all(), JSON_UNESCAPED_UNICODE);

    expect($rows)->not->toContain($secret);
}

describe('autenticación', function () {
    beforeEach(function () {
        $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    });

    it('FR-005 / FR-006: registra el ingreso web con IP y navegador', function () {
        $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);

        $this->withServerVariables(['REMOTE_ADDR' => '190.0.0.1'])
            ->withHeader('User-Agent', 'Mozilla/5.0 Prueba')
            ->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])
            ->assertOk();

        $event = soleSecurityEvent(SecurityEventType::LoginSucceeded);
        expect($event)
            ->actor_id->toBe($user->id)
            ->target_user_id->toBe($user->id)
            ->ip_address->toBe('190.0.0.1')
            ->user_agent->toBe('Mozilla/5.0 Prueba')
            ->metadata->toBe(['channel' => 'web'])
            ->login_attempted->toBeNull();
        expectNoSecretInEvents('secreto-123');
    });

    it('FR-005: registra el ingreso con token (móvil) sin guardar el token', function () {
        User::factory()->create(['username' => 'movil', 'password' => 'secreto-123']);

        $token = $this->postJson('/api/login', ['login' => 'movil', 'password' => 'secreto-123', 'device_name' => 'android'])
            ->assertOk()
            ->json('token');

        expect(soleSecurityEvent(SecurityEventType::LoginSucceeded)->metadata)->toBe(['channel' => 'token']);
        expectNoSecretInEvents(explode('|', $token)[1]);
    });

    it('FR-006: recorta el navegador a 255 caracteres', function () {
        User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);

        $this->withHeader('User-Agent', str_repeat('a', 400))
            ->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])
            ->assertOk();

        expect(soleSecurityEvent(SecurityEventType::LoginSucceeded)->user_agent)->toBe(str_repeat('a', 255));
    });

    it('D-005: un fallo con usuario inexistente guarda el texto intentado sin cuenta y nunca la contraseña', function () {
        $this->postJson('/api/login', ['login' => 'Fantasma', 'password' => 'clave-secreta-999'])->assertUnprocessable();

        $event = soleSecurityEvent(SecurityEventType::LoginFailed);
        expect($event)
            ->actor_id->toBeNull()
            ->target_user_id->toBeNull()
            ->login_attempted->toBe('Fantasma')
            ->metadata->toBe(['reason' => 'invalid']);
        expectNoSecretInEvents('clave-secreta-999');
    });

    it('D-005: un texto demasiado largo se guarda como marcador con su longitud', function () {
        $this->postJson('/api/login', ['login' => str_repeat('x', 300), 'password' => 'y'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed)->login_attempted)->toBe('[texto no válido, 300 caracteres]');
    });

    it('FR-005: un fallo contra una cuenta existente queda asociado a ella', function () {
        $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);

        $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'errada-000'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed))
            ->target_user_id->toBe($user->id)
            ->login_attempted->toBeNull()
            ->metadata->toBe(['reason' => 'invalid']);
        expectNoSecretInEvents('errada-000');
    });

    it('FR-005: el ingreso con contraseña correcta a una cuenta inactiva se registra como inactive', function () {
        User::factory()->inactive()->create(['username' => 'retirado', 'password' => 'secreto-123']);

        $this->postJson('/api/login', ['login' => 'retirado', 'password' => 'secreto-123'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed)->metadata)->toBe(['reason' => 'inactive']);
        expect(securityEventsOf(SecurityEventType::LoginSucceeded))->toBeEmpty();
    });

    it('FR-009: un login rechazado por validación no registra nada', function () {
        $this->postJson('/api/login', ['login' => 'bombero'])->assertUnprocessable();

        expect(SecurityEvent::query()->count())->toBe(0);
    });

    it('FR-005: registra el cierre de sesión web', function () {
        $user = User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);
        $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'secreto-123'])->assertOk();

        $this->postJson('/api/logout')->assertOk();

        expect(soleSecurityEvent(SecurityEventType::Logout))
            ->actor_id->toBe($user->id)
            ->target_user_id->toBe($user->id);
    });

    it('FR-005: registra el cierre de sesión con token', function () {
        $user = User::factory()->create();
        $token = $user->createToken('android')->plainTextToken;

        $this->withToken($token)->postJson('/api/logout')->assertOk();

        expect(soleSecurityEvent(SecurityEventType::Logout)->actor_id)->toBe($user->id);
    });
});

describe('usuarios', function () {
    it('FR-005: registra la creación de un usuario con su rol y sin la contraseña', function () {
        $admin = actingAsRole('admin');
        $role = Role::query()->where('code', Role::CONSULTA)->firstOrFail();

        $id = $this->postJson('/api/users', [
            'name' => 'Nuevo', 'username' => 'nuevo', 'email' => 'nuevo@b31.co', 'role_id' => $role->id,
            'password' => 'temporal-123', 'password_confirmation' => 'temporal-123',
        ])->assertCreated()->json('data.id');

        expect(soleSecurityEvent(SecurityEventType::UserCreated))
            ->actor_id->toBe($admin->id)
            ->target_user_id->toBe($id)
            ->metadata->toBe(['role' => $role->name]);
        expectNoSecretInEvents('temporal-123');
    });

    it('FR-009: si la validación rechaza la creación no hay evento', function () {
        actingAsRole('admin');

        $this->postJson('/api/users', ['name' => 'Sin datos'])->assertUnprocessable();

        expect(SecurityEvent::query()->count())->toBe(0);
    });

    it('FR-005: registra la edición con los valores antes y después, incluido el rol', function () {
        $admin = actingAsRole('admin');
        $almacenista = Role::query()->where('code', Role::ALMACENISTA)->firstOrFail();
        $consulta = Role::query()->where('code', Role::CONSULTA)->firstOrFail();
        $user = User::factory()->create(['name' => 'Ana', 'username' => 'ana', 'role_id' => $consulta->id]);

        $this->patchJson("/api/users/{$user->id}", [
            'name' => 'Ana María', 'username' => 'ana', 'role_id' => $almacenista->id,
        ])->assertOk();

        expect(soleSecurityEvent(SecurityEventType::UserUpdated))
            ->actor_id->toBe($admin->id)
            ->target_user_id->toBe($user->id)
            ->metadata->toBe(['changes' => [
                'name' => ['Ana', 'Ana María'],
                'role' => [$consulta->name, $almacenista->name],
            ]]);
    });

    it('una edición sin cambios no registra evento', function () {
        actingAsRole('admin');
        $user = User::factory()->create(['name' => 'Ana']);

        $this->patchJson("/api/users/{$user->id}", ['name' => 'Ana'])->assertOk();

        expect(securityEventsOf(SecurityEventType::UserUpdated))->toBeEmpty();
    });

    it('FR-009: si quitar el rol al último Administrador se rechaza, no hay evento', function () {
        $admin = actingAsRole('admin');
        $consulta = Role::query()->where('code', Role::CONSULTA)->value('id');

        $this->patchJson("/api/users/{$admin->id}", ['role_id' => $consulta])->assertConflict();

        expect(securityEventsOf(SecurityEventType::UserUpdated))->toBeEmpty();
    });

    it('FR-005: registra la desactivación y la activación', function () {
        $admin = actingAsRole('admin');
        $user = User::factory()->create();

        $this->patchJson("/api/users/{$user->id}/status", ['is_active' => false])->assertOk();
        $this->patchJson("/api/users/{$user->id}/status", ['is_active' => true])->assertOk();

        $events = securityEventsOf(SecurityEventType::UserStatusChanged);
        expect($events)->toHaveCount(2)
            ->and($events[0]->metadata)->toBe(['is_active' => false])
            ->and($events[1]->metadata)->toBe(['is_active' => true])
            ->and($events[0]->actor_id)->toBe($admin->id)
            ->and($events[0]->target_user_id)->toBe($user->id);
    });

    it('FR-009: desactivarse a sí mismo se rechaza sin evento', function () {
        $admin = actingAsRole('admin');

        $this->patchJson("/api/users/{$admin->id}/status", ['is_active' => false])->assertConflict();

        expect(SecurityEvent::query()->count())->toBe(0);
    });

    it('FR-005: registra el restablecimiento de contraseña sin guardarla', function () {
        $admin = actingAsRole('admin');
        $user = User::factory()->create();

        $this->putJson("/api/users/{$user->id}/password", [
            'password' => 'restablecida-123', 'password_confirmation' => 'restablecida-123',
        ])->assertOk();

        expect(soleSecurityEvent(SecurityEventType::PasswordReset))
            ->actor_id->toBe($admin->id)
            ->target_user_id->toBe($user->id)
            ->metadata->toBeNull();
        expectNoSecretInEvents('restablecida-123');
    });

    it('FR-005: registra el cambio de la propia contraseña sin guardarla', function () {
        $user = actingAsRole('consulta', ['password' => 'temporal-123']);

        $this->putJson('/api/user/password', [
            'current_password' => 'temporal-123',
            'password' => 'nueva-clave-456',
            'password_confirmation' => 'nueva-clave-456',
        ])->assertOk();

        expect(soleSecurityEvent(SecurityEventType::PasswordChanged))
            ->actor_id->toBe($user->id)
            ->target_user_id->toBe($user->id);
        expectNoSecretInEvents('nueva-clave-456');
        expectNoSecretInEvents('temporal-123');
    });

    it('FR-009: un cambio de contraseña con la actual errada no registra evento', function () {
        actingAsRole('consulta', ['password' => 'temporal-123']);

        $this->putJson('/api/user/password', [
            'current_password' => 'otra-clave-000',
            'password' => 'nueva-clave-456',
            'password_confirmation' => 'nueva-clave-456',
        ])->assertUnprocessable();

        expect(SecurityEvent::query()->count())->toBe(0);
    });
});

describe('roles y matriz', function () {
    it('FR-005: registra la creación de un rol', function () {
        $admin = actingAsRole('admin');

        $id = $this->postJson('/api/roles', ['name' => 'Bodega', 'description' => 'Turno noche'])
            ->assertCreated()
            ->json('data.id');

        expect(soleSecurityEvent(SecurityEventType::RoleCreated))
            ->actor_id->toBe($admin->id)
            ->target_role_id->toBe($id)
            ->target_user_id->toBeNull()
            ->metadata->toBe(['name' => 'Bodega', 'description' => 'Turno noche']);
    });

    it('FR-009: si la validación rechaza el rol no hay evento', function () {
        actingAsRole('admin');

        $this->postJson('/api/roles', ['name' => ''])->assertUnprocessable();

        expect(SecurityEvent::query()->count())->toBe(0);
    });

    it('FR-005: registra la edición de un rol con los cambios', function () {
        actingAsRole('admin');
        $role = Role::factory()->create(['name' => 'Bodega', 'description' => null]);

        $this->patchJson("/api/roles/{$role->id}", ['name' => 'Bodega central', 'description' => 'Sede'])->assertOk();

        expect(soleSecurityEvent(SecurityEventType::RoleUpdated))
            ->target_role_id->toBe($role->id)
            ->metadata->toBe(['changes' => [
                'name' => ['Bodega', 'Bodega central'],
                'description' => [null, 'Sede'],
            ]]);
    });

    it('FR-005: registra la activación y desactivación de un rol', function () {
        actingAsRole('admin');
        $role = Role::factory()->create();

        $this->patchJson("/api/roles/{$role->id}/status", ['is_active' => false])->assertOk();

        expect(soleSecurityEvent(SecurityEventType::RoleStatusChanged))
            ->target_role_id->toBe($role->id)
            ->metadata->toBe(['is_active' => false]);
    });

    it('FR-009: desactivar un rol con usuarios activos se rechaza sin evento', function () {
        actingAsRole('admin');
        $role = Role::factory()->create();
        User::factory()->create(['role_id' => $role->id]);

        $this->patchJson("/api/roles/{$role->id}/status", ['is_active' => false])->assertConflict();

        expect(SecurityEvent::query()->count())->toBe(0);
    });

    it('FR-005: registra los permisos agregados y quitados en la matriz', function () {
        $admin = actingAsRole('admin');
        $role = Role::query()->where('code', Role::CONSULTA)->firstOrFail();
        $before = $role->permissions()->pluck('code')->all();
        expect($before)->toContain('activos.ver');

        $after = array_values(array_diff($before, ['activos.ver']));
        $after[] = 'entregas.gestionar';

        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => $after])->assertOk();

        $event = soleSecurityEvent(SecurityEventType::RolePermissionsUpdated);
        expect($event->actor_id)->toBe($admin->id)
            ->and($event->target_role_id)->toBe($role->id)
            ->and($event->metadata['removed'])->toBe(['activos.ver'])
            ->and($event->metadata['added'])->toEqualCanonicalizing(
                array_values(array_diff(['entregas.gestionar', 'entregas.ver'], $before)),
            );
    });

    it('FR-009: la matriz rechazada (permiso exclusivo del Administrador) no registra evento', function () {
        actingAsRole('admin');
        $role = Role::query()->where('code', Role::CONSULTA)->firstOrFail();

        $this->putJson("/api/roles/{$role->id}/permissions", ['permissions' => ['usuarios.gestionar']])->assertUnprocessable();

        expect(SecurityEvent::query()->count())->toBe(0);
    });
});

// Correcciones de la revisión de seguridad de 003 (A-1, M-3, B-1).

describe('IP del cliente detrás del proxy (A-1)', function () {
    beforeEach(function () {
        $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    });

    it('A-1: guarda la IP agregada por el proxy inmediato, no la que el cliente inventa en X-Forwarded-For', function () {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', '1.2.3.4, 203.0.113.9')
            ->postJson('/api/login', ['login' => 'fantasma', 'password' => 'x'])
            ->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed)->ip_address)->toBe('203.0.113.9');
    });

    it('A-1: el rate limiting de login usa la IP agregada por el proxy inmediato', function () {
        $attempt = fn (string $forwardedFor) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->withHeader('X-Forwarded-For', $forwardedFor)
            ->postJson('/api/login', ['login' => 'fantasma', 'password' => 'x']);

        // El atacante rota el primer valor de X-Forwarded-For: no le sirve.
        foreach (range(1, 10) as $i) {
            $attempt("1.2.3.{$i}, 203.0.113.9")->assertUnprocessable();
        }
        $attempt('1.2.3.99, 203.0.113.9')->assertTooManyRequests();

        // Otro cliente real detrás del mismo proxy no queda bloqueado.
        $attempt('1.2.3.4, 203.0.113.10')->assertUnprocessable();
    });
});

describe('texto intentado en ingresos fallidos (M-3, D-005)', function () {
    beforeEach(function () {
        $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    });

    it('M-3: no guarda el texto intentado cuando la cuenta existe', function () {
        User::factory()->create(['username' => 'bombero', 'password' => 'secreto-123']);

        $this->postJson('/api/login', ['login' => 'Bombero', 'password' => 'errada-000'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed)->login_attempted)->toBeNull();
    });

    it('M-3: no guarda el texto intentado cuando la cuenta existente está bloqueada', function () {
        User::factory()->create([
            'username' => 'bombero',
            'failed_login_attempts' => 5,
            'locked_until' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/login', ['login' => 'bombero', 'password' => 'x'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed))
            ->login_attempted->toBeNull()
            ->metadata->toBe(['reason' => 'locked']);
    });

    it('M-3: guarda un correo con forma válida de una cuenta inexistente', function () {
        $this->postJson('/api/login', ['login' => 'Nadie@Example.com', 'password' => 'x'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed)->login_attempted)->toBe('Nadie@Example.com');
    });

    it('M-3: reemplaza por un marcador el texto sin forma de usuario ni de correo', function (string $login, int $length) {
        $this->postJson('/api/login', ['login' => $login, 'password' => 'x'])->assertUnprocessable();

        expect(soleSecurityEvent(SecurityEventType::LoginFailed)->login_attempted)
            ->toBe("[texto no válido, {$length} caracteres]");
    })->with([
        'posible contraseña con espacios y símbolos' => ['Mi clave Secreta!', 17],
        'demasiado corto' => ['ab', 2],
        'demasiado largo' => [str_repeat('x', 41), 41],
        'tildes' => ['josé', 4],
        'correo de más de 255 caracteres' => [str_repeat('a', 60).'@'.str_repeat('b', 63).'.'.str_repeat('c', 63).'.'.str_repeat('d', 63).'.com', 256],
    ]);
});

describe('canal de tiempo en ingresos fallidos (B-1)', function () {
    beforeEach(function () {
        $this->withHeader('Origin', 'http://'.config('sanctum.stateful')[0]);
    });

    /**
     * Cuenta los UPDATE de users que ejecuta un ingreso fallido.
     */
    function failedLoginUserUpdates(string $login): int
    {
        $updates = 0;
        DB::listen(function ($query) use (&$updates) {
            if (str_contains($query->sql, 'UPDATE users SET')) {
                $updates++;
            }
        });

        test()->postJson('/api/login', ['login' => $login, 'password' => 'x'])->assertUnprocessable();

        return $updates;
    }

    it('B-1: una cuenta inexistente ejecuta el mismo UPDATE de conteo que una existente', function () {
        expect(failedLoginUserUpdates('fantasma'))->toBe(1);
    });

    it('B-1: una cuenta bloqueada ejecuta el mismo UPDATE sin extender el bloqueo', function () {
        $lockedUntil = now()->addMinutes(10)->startOfSecond();
        $user = User::factory()->create([
            'username' => 'bombero',
            'failed_login_attempts' => 5,
            'locked_until' => $lockedUntil,
        ]);

        expect(failedLoginUserUpdates('bombero'))->toBe(1);
        expect($user->fresh())
            ->failed_login_attempts->toBe(5)
            ->locked_until->equalTo($lockedUntil)->toBeTrue();
        expect(securityEventsOf(SecurityEventType::AccountLocked))->toBeEmpty();
    });

    it('B-1: una contraseña errada contra una cuenta existente ejecuta un UPDATE', function () {
        User::factory()->create(['username' => 'bombero']);

        expect(failedLoginUserUpdates('bombero'))->toBe(1);
    });
});
