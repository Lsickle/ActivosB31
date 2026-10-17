<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Roles y matriz de permisos (RN-009): "usuarios.ver" consulta,
 * "usuarios.gestionar" modifica; este último es exclusivo del Administrador
 * (RN-019). Sin Gate::before (M-5), las reglas aplican también al
 * Administrador.
 */
class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::UsuariosVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }

    public function updateStatus(User $user, Role $role): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }

    /**
     * RN-010: los permisos del rol Administrador no se modifican, ni siquiera
     * por otro Administrador.
     */
    public function updatePermissions(User $user, Role $role): Response
    {
        if ($role->isAdmin()) {
            return Response::deny('Los permisos del rol Administrador no se pueden modificar.');
        }

        return $user->hasPermission(Permission::UsuariosGestionar)
            ? Response::allow()
            : Response::deny();
    }
}
