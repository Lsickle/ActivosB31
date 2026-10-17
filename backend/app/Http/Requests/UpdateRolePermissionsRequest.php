<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Casillas de la matriz para un rol (RN-009, D-010). RolePolicy rechaza el
 * rol Administrador (RN-010); aquí se rechazan los permisos exclusivos del
 * Administrador para cualquier otro rol (RN-019).
 */
class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * Gate::authorize lanza el 403 con el mensaje de la Policy (p. ej. rol
     * Administrador), antes de validar el cuerpo.
     */
    public function authorize(): bool
    {
        Gate::authorize('updatePermissions', $this->route('role'));

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => [
                'present',
                'array',
                function (string $attribute, mixed $value, Closure $fail) {
                    $adminOnly = array_filter(Permission::cases(), fn (Permission $permission) => $permission->isAdminOnly());

                    foreach ($adminOnly as $permission) {
                        if (is_array($value) && in_array($permission->value, $value, true)) {
                            $fail("El permiso «{$permission->label()}» es exclusivo del rol Administrador.");
                        }
                    }
                },
            ],
            'permissions.*' => ['string', 'distinct', Rule::enum(Permission::class)],
        ];
    }

    /**
     * Permisos pedidos, normalizados: gestionar implica ver (FR-002).
     *
     * @return list<Permission>
     */
    public function normalizedPermissions(): array
    {
        $requested = array_map(Permission::from(...), $this->validated('permissions'));

        $withView = [];
        foreach ($requested as $permission) {
            $withView[] = $permission;
            $withView[] = $permission->viewPermission();
        }

        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission) => in_array($permission, $withView, true),
        ));
    }
}
