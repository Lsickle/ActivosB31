<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\User;

/**
 * FR-008: la bitácora de seguridad solo se consulta con usuarios.gestionar,
 * exclusivo del Administrador (RN-019). Contiene IP y navegador, datos
 * personales (Ley 1581). No hay permisos de escritura: los eventos los crea
 * la aplicación (RecordSecurityEvent) y son inmutables (FR-007).
 */
class SecurityEventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permission::UsuariosGestionar);
    }
}
