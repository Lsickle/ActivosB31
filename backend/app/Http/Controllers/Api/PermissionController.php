<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PermissionController extends Controller
{
    /**
     * Catálogo fijo agrupado por módulo, en el orden de la matriz (RN-009).
     * Sale del enum, que es la fuente de verdad (D-002).
     */
    public function index(): JsonResponse
    {
        Gate::authorize(Permission::UsuariosVer->value);

        $modules = [];
        foreach (Permission::modules() as $module => $label) {
            $modules[] = [
                'module' => $module,
                'label' => $label,
                'permissions' => array_values(array_map(
                    fn (Permission $permission) => ['code' => $permission->value, 'action' => $permission->action()],
                    array_filter(Permission::cases(), fn (Permission $permission) => $permission->module() === $module),
                )),
            ];
        }

        return response()->json(['data' => $modules]);
    }
}
