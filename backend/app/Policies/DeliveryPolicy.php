<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Delivery;
use App\Models\User;

/**
 * FR-006 (007): ver entregas, su ficha y las alertas de préstamos vencidos
 * exige entregas.ver; registrar entregas y devoluciones exige
 * entregas.gestionar. Las entregas no se editan ni se borran (FR-001). Sin
 * Gate::before: aplica también al Administrador.
 */
class DeliveryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::EntregasVer);
    }

    public function view(User $user, Delivery $delivery): bool
    {
        return $user->hasPermission(Permission::EntregasVer);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permission::EntregasGestionar);
    }

    /**
     * M-1: ver la firma del empleado (enlace temporal signature_url) exige
     * entregas.gestionar o empleados.ver; con solo entregas.ver la ficha se
     * muestra sin la imagen de la firma.
     *
     * RN pendiente: pregunta 33 (provisional 33A).
     */
    public function viewSignature(User $user, Delivery $delivery): bool
    {
        return $user->hasPermission(Permission::EntregasGestionar)
            || $user->hasPermission(Permission::EmpleadosVer);
    }

    /**
     * FR-003: registrar devoluciones de una entrega.
     */
    public function registerReturn(User $user, Delivery $delivery): bool
    {
        return $user->hasPermission(Permission::EntregasGestionar);
    }
}
