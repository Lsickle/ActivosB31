<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Employee;
use App\Models\User;

/**
 * RN-022 / FR-005: los datos personales de los empleados (Ley 1581 de 2012)
 * se consultan con empleados.ver y se modifican con empleados.gestionar. No
 * hay eliminación (RN-021). Sin Gate::before: aplica también al
 * Administrador.
 */
class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::EmpleadosVer);
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->hasPermission(Permission::EmpleadosVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::EmpleadosGestionar);
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->hasPermission(Permission::EmpleadosGestionar);
    }

    public function updateStatus(User $user, Employee $employee): bool
    {
        return $user->hasPermission(Permission::EmpleadosGestionar);
    }

    /**
     * D-004 / FR-006 / US3-2: la lista mínima (id, nombre completo, cargo),
     * sin datos personales, la usan también Entregas y Reportes.
     */
    public function viewOptions(User $user): bool
    {
        return $user->hasPermission(Permission::EmpleadosVer)
            || $user->hasPermission(Permission::EntregasVer)
            || $user->hasPermission(Permission::ReportesVer);
    }
}
