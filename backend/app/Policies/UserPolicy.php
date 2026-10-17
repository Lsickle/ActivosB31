<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * Gestión de usuarios (RN-009): "usuarios.ver" consulta, "usuarios.gestionar"
 * modifica; este último es exclusivo del Administrador (RN-019). Las invariantes
 * sobre el registro (último Administrador, desactivarse a sí mismo) se
 * responden con 409 desde el controlador, no aquí.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::UsuariosVer);
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::UsuariosVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }

    /**
     * RN-015 / RN-022: asignar o cambiar el empleado vinculado expone datos de
     * Empleados, así que exige además empleados.ver.
     */
    public function linkEmployee(User $user): bool
    {
        return $user->hasPermission(Permission::EmpleadosVer);
    }

    public function updateStatus(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }

    public function resetPassword(User $user, User $model): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }
}
