<?php

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// FR-007 / SC-003 (D-002): la bitácora es de solo inserción, tanto desde el
// modelo como con SQL directo del usuario de la aplicación.

function makeSecurityEvent(): SecurityEvent
{
    $user = User::factory()->create();

    return SecurityEvent::query()->create([
        'event' => SecurityEventType::LoginSucceeded,
        'actor_id' => $user->id,
        'target_user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'test',
        'metadata' => ['channel' => 'web'],
    ]);
}

it('FR-007: guarda un evento sin updated_at', function () {
    $event = makeSecurityEvent();

    expect($event->fresh())
        ->event->toBe(SecurityEventType::LoginSucceeded)
        ->metadata->toBe(['channel' => 'web'])
        ->created_at->not->toBeNull()
        ->and(array_key_exists('updated_at', $event->fresh()->getAttributes()))->toBeFalse();
});

it('FR-007: el modelo rechaza modificar un evento existente', function () {
    $event = makeSecurityEvent();

    expect(fn () => $event->update(['ip_address' => '1.1.1.1']))->toThrow(LogicException::class);
    expect(fn () => $event->forceFill(['ip_address' => '1.1.1.1'])->save())->toThrow(LogicException::class);
    expect($event->fresh()->ip_address)->toBe('10.0.0.1');
});

it('FR-007: el modelo rechaza borrar un evento', function () {
    $event = makeSecurityEvent();

    expect(fn () => $event->delete())->toThrow(LogicException::class);
    expect(SecurityEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

it('FR-007: la base de datos rechaza UPDATE directo', function () {
    $event = makeSecurityEvent();

    expect(fn () => inSavepoint(fn () => DB::update("UPDATE security_events SET event = 'x' WHERE id = ?", [$event->id])))
        ->toThrow(QueryException::class, 'solo inserción');

    expect(fn () => inSavepoint(fn () => SecurityEvent::query()->whereKey($event->id)->update(['ip_address' => '1.1.1.1'])))
        ->toThrow(QueryException::class, 'solo inserción');

    expect($event->fresh()->event)->toBe(SecurityEventType::LoginSucceeded);
});

it('FR-007: la base de datos rechaza DELETE directo', function () {
    $event = makeSecurityEvent();

    expect(fn () => inSavepoint(fn () => DB::delete('DELETE FROM security_events WHERE id = ?', [$event->id])))
        ->toThrow(QueryException::class, 'solo inserción');

    expect(SecurityEvent::query()->whereKey($event->id)->exists())->toBeTrue();
});

it('FR-007: la base de datos rechaza TRUNCATE', function () {
    makeSecurityEvent();

    expect(fn () => inSavepoint(fn () => DB::statement('TRUNCATE security_events')))
        ->toThrow(QueryException::class, 'solo inserción');

    expect(SecurityEvent::query()->count())->toBe(1);
});
