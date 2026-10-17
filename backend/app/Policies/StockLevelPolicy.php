<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * FR-006 (006): existencias, resumen de serializados y alertas de stock
 * mínimo (RN-028) exigen inventario.ver. Las existencias no se editan: solo
 * cambian con movimientos (InventoryMovementPolicy).
 */
class StockLevelPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::InventarioVer);
    }
}
