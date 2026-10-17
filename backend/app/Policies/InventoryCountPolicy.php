<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\InventoryCount;
use App\Models\User;

/**
 * FR-006 (006): ver conteos exige inventario.ver; abrirlos, registrar sus
 * renglones y cerrarlos, inventario.gestionar (RN-027). Sin Gate::before.
 */
class InventoryCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::InventarioVer);
    }

    public function view(User $user, InventoryCount $count): bool
    {
        return $user->hasPermission(Permission::InventarioVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::InventarioGestionar);
    }

    public function update(User $user, InventoryCount $count): bool
    {
        return $user->hasPermission(Permission::InventarioGestionar);
    }
}
