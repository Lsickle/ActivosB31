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

    /**
     * Contrato de 007 (permisos cruzados): el asistente de entrega busca
     * activos Disponibles con GET /api/assets. Con entregas.gestionar (sin
     * activos.ver) el listado se limita a los Disponibles (ListAssetsRequest),
     * que no tienen poseedor. No abre la ficha, el historial ni las alertas.
     */
    public function viewAvailableForDelivery(User $user): bool
    {
        return $user->hasPermission(Permission::EntregasGestionar);
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
