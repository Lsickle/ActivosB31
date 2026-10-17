<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * RN-040 / D-006: el rol precargado Consulta no tiene empleados.ver
     * (RN-022). RoleSeeder solo asigna permisos al crear el rol, así que en
     * las instalaciones existentes se retira aquí, una sola vez. Idempotente
     * y sin efecto si aún no hay roles ni permisos (instalación nueva: la
     * precarga ya lo omite). Desde aquí se puede volver a conceder en la
     * matriz.
     */
    public function up(): void
    {
        $roleId = DB::table('roles')->where('code', 'consulta')->value('id');
        // E-5: también se quita empleados.gestionar para no dejar "gestionar
        // sin ver" (gestionar implica ver, FR-002 de 001).
        $permissionIds = DB::table('permissions')
            ->whereIn('code', ['empleados.ver', 'empleados.gestionar'])
            ->pluck('id');

        if ($roleId === null || $permissionIds->isEmpty()) {
            return;
        }

        DB::table('role_permissions')
            ->where('role_id', $roleId)
            ->whereIn('permission_id', $permissionIds)
            ->delete();
    }

    /**
     * Sin reversión: no se sabe si el permiso existía antes.
     */
    public function down(): void {}
};
