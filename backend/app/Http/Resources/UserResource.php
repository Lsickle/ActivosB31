<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    /**
     * Relaciones que debe precargar quien construya el recurso (rol y, por
     * RN-015, el empleado vinculado).
     *
     * @var list<string>
     */
    public const array RELATIONS = ['role', 'employee:id,first_name,last_name'];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'last_login_at' => $this->last_login_at,
            // RN-034 / FR-004: solo mientras el bloqueo esté vigente.
            'locked_until' => $this->isLocked() ? $this->locked_until : null,
            'role' => $this->whenLoaded('role', fn () => $this->role->only(['id', 'name', 'code'])),
            // RN-015: empleado vinculado (opcional).
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->full_name,
            ]),
            'created_at' => $this->created_at,
        ];
    }
}
