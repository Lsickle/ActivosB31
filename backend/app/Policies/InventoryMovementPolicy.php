<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * FR-006 (006): ver movimientos exige inventario.ver; registrarlos,
 * inventario.gestionar. No hay edición ni borrado (FR-001): un error se
 * corrige con un movimiento inverso. Sin Gate::before.
 */
class InventoryMovementPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::InventarioVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::InventarioGestionar);
    }
}
