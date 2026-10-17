<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionCode;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * Roles precargados (RN-010) con sus permisos iniciales (FR-003, RN-011).
 * Idempotente: los permisos iniciales se asignan solo al crear el rol, para
 * no pisar los cambios hechos desde la matriz; el Administrador, en cambio,
 * se re-sincroniza siempre con todo el catálogo.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->definitions() as $code => $definition) {
            $role = Role::query()->where('code', $code)->first();

            if ($role === null) {
                $role = Role::query()->forceCreate([
                    'code' => $code,
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'is_system' => true,
                    'is_active' => true,
                ]);

                $this->syncPermissions($role, $definition['permissions']);
            }

            if ($role->isAdmin()) {
                $this->syncPermissions($role, PermissionCode::cases());
            }
        }

        $this->revokeAdminOnlyPermissionsFromOtherRoles();
    }

    /**
     * RN-019: los permisos exclusivos del Administrador nunca quedan en otro
     * rol, aunque alguien los haya insertado por fuera de la matriz.
     */
    private function revokeAdminOnlyPermissionsFromOtherRoles(): void
    {
        $adminOnlyCodes = array_map(
            fn (PermissionCode $permission) => $permission->value,
            array_filter(PermissionCode::cases(), fn (PermissionCode $permission) => $permission->isAdminOnly()),
        );

        $adminOnlyIds = Permission::query()->whereIn('code', $adminOnlyCodes)->pluck('id');

        Role::query()
            ->where(fn ($query) => $query->whereNull('code')->orWhere('code', '!=', Role::ADMIN))
            ->each(fn (Role $role) => $role->permissions()->detach($adminOnlyIds));
    }

    /**
     * @param  list<PermissionCode>  $permissions
     */
    private function syncPermissions(Role $role, array $permissions): void
    {
        $role->permissions()->sync(
            Permission::query()
                ->whereIn('code', array_map(fn (PermissionCode $permission) => $permission->value, $permissions))
                ->pluck('id'),
        );
    }

    /**
     * @return array<string, array{name: string, description: string, permissions: list<PermissionCode>}>
     */
    private function definitions(): array
    {
        $allView = array_values(array_filter(
            PermissionCode::cases(),
            fn (PermissionCode $permission) => $permission->action() === PermissionCode::ACTION_VIEW,
        ));

        return [
            Role::ADMIN => [
                'name' => 'Administrador',
                'description' => 'Todos los permisos; no se pueden quitar.',
                'permissions' => PermissionCode::cases(),
            ],
            Role::ALMACENISTA => [
                'name' => 'Almacenista',
                'description' => 'Gestiona activos, inventario y entregas; consulta los demás módulos.',
                'permissions' => [
                    ...$allView,
                    PermissionCode::ActivosGestionar,
                    PermissionCode::InventarioGestionar,
                    PermissionCode::EntregasGestionar,
                ],
            ],
            // RN-040: sin empleados.ver (datos personales, RN-022); se puede
            // conceder desde la matriz. Las instalaciones previas lo pierden
            // con la migración de datos 2026_10_17_500004 (D-006).
            Role::CONSULTA => [
                'name' => 'Consulta',
                'description' => 'Consulta los módulos sin modificar; no ve los datos personales de los empleados.',
                'permissions' => array_values(array_filter(
                    $allView,
                    fn (PermissionCode $permission) => $permission !== PermissionCode::EmpleadosVer,
                )),
            ],
        ];
    }
}
