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

    /**
     * Contrato de 007 (permisos cruzados): el asistente de entrega muestra la
     * existencia del tipo en la ubicación de origen con GET
     * /api/inventory/stock. No abre el resumen de serializados ni las
     * alertas de stock mínimo.
     */
    public function viewForDelivery(User $user): bool
    {
        return $user->hasPermission(Permission::EntregasGestionar);
    }
}
