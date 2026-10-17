<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Asset;
use App\Models\User;

/**
 * FR-007: ver activos, su historial, planes y mantenimientos exige
 * activos.ver; registrar, editar, cambiar estado o ubicación, la foto y los
 * mantenimientos exige activos.gestionar. No hay eliminación (FR-001). Sin
 * Gate::before: aplica también al Administrador.
 */
class AssetPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ActivosVer);
    }

    public function view(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permission::ActivosVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ActivosGestionar);
    }

    public function update(User $user, Asset $asset): bool
    {
        return $user->hasPermission(Permission::ActivosGestionar);
    }
}
