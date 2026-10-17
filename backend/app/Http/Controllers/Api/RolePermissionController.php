<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Enums\Permission as PermissionCode;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Resources\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

class RolePermissionController extends Controller
{
    /**
     * Reemplaza las casillas de un rol en la matriz (RN-009, D-010). Rige
     * desde la siguiente petición de sus usuarios (SC-003): no hay caché.
     * A-1: registra los permisos agregados y quitados (sin evento si la
     * matriz no cambió), en la misma transacción (FR-009).
     */
    public function update(UpdateRolePermissionsRequest $request, Role $role, RecordSecurityEvent $recordEvent): RoleResource
    {
        $codes = array_map(fn (PermissionCode $permission) => $permission->value, $request->normalizedPermissions());

        DB::transaction(function () use ($request, $role, $codes, $recordEvent) {
            $result = $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id'));

            $codeOf = fn (array $ids) => Permission::query()->whereKey($ids)->orderBy('code')->pluck('code')->all();
            $added = $codeOf($result['attached']);
            $removed = $codeOf($result['detached']);

            if ($added !== [] || $removed !== []) {
                $recordEvent->handle(SecurityEventType::RolePermissionsUpdated, $request->user(), role: $role, metadata: [
                    'added' => $added,
                    'removed' => $removed,
                ]);
            }
        });

        return RoleResource::make($role->load('permissions:id,code')->loadCount(Role::activeUsersCount()));
    }
}
