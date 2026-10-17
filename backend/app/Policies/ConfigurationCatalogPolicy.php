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
