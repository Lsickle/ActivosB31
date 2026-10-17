<?php

namespace App\Http\Resources;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Role
 */
class RoleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'is_system' => $this->is_system,
            'is_active' => $this->is_active,
            'users_count' => $this->whenCounted('users'),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->orderedPermissionCodes()),
        ];
    }

    /**
     * Códigos en el orden del catálogo (el de la matriz).
     *
     * @return list<string>
     */
    private function orderedPermissionCodes(): array
    {
        $granted = $this->permissions->pluck('code')->all();

        return array_values(array_filter(
            Permission::values(),
            fn (string $code) => in_array($code, $granted, true),
        ));
    }
}
