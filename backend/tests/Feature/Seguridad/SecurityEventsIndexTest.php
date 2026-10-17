<?php

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

// FR-008 (D-006): consulta de la bitácora solo con usuarios.gestionar
// (exclusivo del Administrador, RN-019); filtros por tipo, usuario (actor o
// afectado) y fechas en America/Bogota; del más reciente al más antiguo.

/**
 * @param  array<string, mixed>  $attributes
 */
function logEventAt(string $utc, array $attributes = []): SecurityEvent
{
    Carbon::setTestNow(Carbon::parse($utc, 'UTC'));

    $event = SecurityEvent::query()->create([
        'event' => SecurityEventType::LoginSucceeded,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'test',
        ...$attributes,
    ]);

    Carbon::setTestNow();

    return $event;
}

it('FR-008: el Administrador ve los eventos del más reciente al más antiguo, con el contrato', function () {
    $admin = actingAsRole('admin');
    $role = Role::query()->where('code', Role::ALMACENISTA)->firstOrFail();
    $older = logEventAt('2026-10-17 12:00:00', ['actor_id' => $admin->id, 'target_user_id' => $admin->id]);
    $newer = logEventAt('2026-10-17 20:04:05', [
        'event' => SecurityEventType::RolePermissionsUpdated,
        'actor_id' => $admin->id,
        'target_role_id' => $role->id,
        'ip_address' => '190.0.0.1',
        'user_agent' => 'Mozilla/5.0',
        'metadata' => ['added' => ['reportes.ver'], 'removed' => []],
    ]);

    $this->getJson('/api/security-events')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('meta.per_page', 25)
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id)
        ->assertJsonPath('data.0', [
            'id' => $newer->id,
            'event' => 'role_permissions_updated',
            'event_label' => 'Matriz de permisos modificada',
            'created_at' => '2026-10-17T15:04:05-05:00',
            'actor' => ['id' => $admin->id, 'name' => $admin->name, 'username' => $admin->username],
            'target_user' => null,
            'target_role' => ['id' => $role->id, 'name' => $role->name],
            'login_attempted' => null,
            'ip_address' => '190.0.0.1',
            'user_agent' => 'Mozilla/5.0',
            'metadata' => ['added' => ['reportes.ver'], 'removed' => []],
        ]);
});

it('FR-008: a igual fecha ordena por id descendente', function () {
    actingAsRole('admin');
    $first = logEventAt('2026-10-17 12:00:00');
    $second = logEventAt('2026-10-17 12:00:00');

    $this->getJson('/api/security-events')
        ->assertJsonPath('data.0.id', $second->id)
        ->assertJsonPath('data.1.id', $first->id);
});

it('RN-019: sin usuarios.gestionar la consulta se rechaza', function (string $role) {
    actingAsRole($role);

    $this->getJson('/api/security-events')->assertForbidden();
    $this->getJson('/api/security-events/types')->assertForbidden();
})->with(['almacenista', 'consulta']);

it('RN-019: usuarios.ver no basta para consultar la bitácora', function () {
    actingWithPermissions(['usuarios.ver']);

    $this->getJson('/api/security-events')->assertForbidden();
});

it('exige autenticación', function () {
    $this->getJson('/api/security-events')->assertUnauthorized();
});

it('filtra por tipo de evento', function () {
    actingAsRole('admin');
    logEventAt('2026-10-17 12:00:00');
    $failed = logEventAt('2026-10-17 12:00:00', ['event' => SecurityEventType::LoginFailed]);

    $this->getJson('/api/security-events?event=login_failed')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $failed->id);
});

it('filtra por usuario como actor o como afectado', function () {
    actingAsRole('admin');
    $ana = User::factory()->create();
    $otro = User::factory()->create();
    $asActor = logEventAt('2026-10-17 12:00:00', ['actor_id' => $ana->id, 'target_user_id' => $otro->id]);
    $asTarget = logEventAt('2026-10-17 13:00:00', ['actor_id' => $otro->id, 'target_user_id' => $ana->id]);
    logEventAt('2026-10-17 14:00:00', ['actor_id' => $otro->id, 'target_user_id' => $otro->id]);

    $ids = collect($this->getJson("/api/security-events?user_id={$ana->id}")->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$asTarget->id, $asActor->id]);
});

it('D-006: filtra por fechas inclusivas en hora de Bogotá', function () {
    actingAsRole('admin');
    // 2026-10-16 23:30 en Bogotá = 2026-10-17 04:30 UTC.
    $lateOn16 = logEventAt('2026-10-17 04:30:00');
    // 2026-10-17 00:30 en Bogotá = 2026-10-17 05:30 UTC.
    $earlyOn17 = logEventAt('2026-10-17 05:30:00');
    // 2026-10-17 23:59 en Bogotá = 2026-10-18 04:59 UTC.
    $lateOn17 = logEventAt('2026-10-18 04:59:00');
    // 2026-10-18 00:00 en Bogotá = 2026-10-18 05:00 UTC.
    $startOn18 = logEventAt('2026-10-18 05:00:00');

    $ids = fn (string $query) => collect($this->getJson("/api/security-events?{$query}")->assertOk()->json('data'))->pluck('id')->sort()->values()->all();

    expect($ids('from=2026-10-17&to=2026-10-17'))->toBe([$earlyOn17->id, $lateOn17->id])
        ->and($ids('from=2026-10-17'))->toBe([$earlyOn17->id, $lateOn17->id, $startOn18->id])
        ->and($ids('to=2026-10-16'))->toBe([$lateOn16->id]);
});

it('combina todos los filtros', function () {
    actingAsRole('admin');
    $ana = User::factory()->create();
    $match = logEventAt('2026-10-17 15:00:00', ['event' => SecurityEventType::LoginFailed, 'target_user_id' => $ana->id]);
    logEventAt('2026-10-17 15:00:00', ['event' => SecurityEventType::LoginSucceeded, 'target_user_id' => $ana->id]);
    logEventAt('2026-10-17 15:00:00', ['event' => SecurityEventType::LoginFailed]);
    logEventAt('2026-10-19 15:00:00', ['event' => SecurityEventType::LoginFailed, 'target_user_id' => $ana->id]);

    $this->getJson("/api/security-events?event=login_failed&user_id={$ana->id}&from=2026-10-17&to=2026-10-18")
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $match->id);
});

it('pagina con per_page hasta 100', function () {
    actingAsRole('admin');
    foreach (range(1, 3) as $_) {
        logEventAt('2026-10-17 12:00:00');
    }

    $this->getJson('/api/security-events?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2);

    $this->getJson('/api/security-events?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
});

it('valida los filtros', function () {
    actingAsRole('admin');

    $this->getJson('/api/security-events?event=desconocido&from=17-10-2026&to=2026-10-01&user_id=abc')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['event', 'from', 'user_id']);

    $this->getJson('/api/security-events?from=2026-10-17&to=2026-10-01')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['to']);
});

it('FR-008: lista los tipos de evento con su etiqueta para el filtro', function () {
    actingAsRole('admin');

    $response = $this->getJson('/api/security-events/types')->assertOk();

    expect($response->json('data'))->toHaveCount(count(SecurityEventType::cases()))
        ->and($response->json('data.0'))->toBe(['code' => 'login_succeeded', 'label' => 'Ingreso exitoso']);
});
