<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogos de Configuración (D-001, RN-011): "configuracion.ver" consulta y
 * "configuracion.gestionar" crea, edita, desactiva o reactiva. No hay
 * eliminación (RN-003). Sin Gate::before: aplica también al Administrador.
 * Cada catálogo tiene su subclase para que Laravel la descubra por
 * convención.
 */
abstract class ConfigurationCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::ConfiguracionVer);
    }

    /**
     * D-005 (resuelto 2026-10-17) / RN-011: las opciones de catálogo
     * (nombres, sin datos sensibles) las lee cualquier usuario con al menos
     * un permiso de ver, para que un rol con p. ej. empleados.gestionar sin
     * configuracion.ver pueda llenar sus formularios. La cuenta inactiva ya
     * la corta el middleware "active".
     */
    public function viewOptions(User $user): bool
    {
        foreach ($user->permissionCodes() as $code) {
            if (Permission::tryFrom($code)?->action() === Permission::ACTION_VIEW) {
                return true;
            }
        }

        return false;
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permission::ConfiguracionVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::ConfiguracionGestionar);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission(Permission::ConfiguracionGestionar);
    }

    public function updateStatus(User $user, Model $record): bool
    {
        return $user->hasPermission(Permission::ConfiguracionGestionar);
    }
}
