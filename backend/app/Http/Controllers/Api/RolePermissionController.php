<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission as PermissionCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;

class RolePermissionController extends Controller
{
    /**
     * Reemplaza las casillas de un rol en la matriz (RN-009, D-010). Rige
     * desde la siguiente petición de sus usuarios (SC-003): no hay caché.
     */
    public function update(UpdateRolePermissionsRequest $request, Role $role): RoleResource
    {
        $codes = array_map(fn (PermissionCode $permission) => $permission->value, $request->normalizedPermissions());

        $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));

        return RoleResource::make($role->load('permissions:id,code')->loadCount(Role::activeUsersCount()));
    }
}
