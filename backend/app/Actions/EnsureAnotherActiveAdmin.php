<?php

namespace App\Actions;

use App\Models\Role;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * FR-012 / SC-005 (D-007): la aplicación nunca queda sin un Administrador
 * activo. Se llama dentro de una transacción, antes de desactivar a un
 * usuario o quitarle el rol Administrador: bloquea las filas de los
 * administradores activos (lockForUpdate) para que dos administradores no
 * puedan desactivarse mutuamente a la vez.
 */
class EnsureAnotherActiveAdmin
{
    public const string MESSAGE = 'Debe quedar al menos un Administrador activo.';

    /**
     * @throws ConflictHttpException si $user es el último Administrador activo
     */
    public function handle(User $user): void
    {
        $activeAdminIds = User::query()
            ->whereIn('role_id', Role::query()->where('code', Role::ADMIN)->select('id'))
            ->where('is_active', true)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id');

        if ($activeAdminIds->contains($user->getKey()) && $activeAdminIds->count() === 1) {
            throw new ConflictHttpException(self::MESSAGE);
        }
    }
}
