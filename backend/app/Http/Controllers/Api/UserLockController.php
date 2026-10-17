<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * RN-034 / FR-004 (D-007): desbloqueo manual de una cuenta antes de que
 * venza el plazo. Exige usuarios.gestionar (UserPolicy::update), exclusivo
 * del Administrador (RN-019).
 */
class UserLockController extends Controller
{
    /**
     * Si la cuenta no está bloqueada no hace nada ni registra evento. La
     * fila se bloquea (lockForUpdate) para no competir con un fallo de login
     * simultáneo.
     */
    public function __invoke(Request $request, User $user, RecordSecurityEvent $recordEvent): UserResource
    {
        Gate::authorize('update', $user);

        DB::transaction(function () use ($request, $user, $recordEvent) {
            $current = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! $current->isLocked()) {
                return;
            }

            $current->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();

            $recordEvent->handle(SecurityEventType::AccountUnlocked, $request->user(), $current);
        });

        return UserResource::make($user->refresh()->load(UserResource::RELATIONS));
    }
}
