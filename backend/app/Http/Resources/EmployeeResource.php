<?php

namespace App\Http\Resources;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Empleado completo, con datos personales (RN-022): solo para quien tiene
 * empleados.ver. Los demás módulos usan /api/employees/options (D-004).
 *
 * @mixin Employee
 */
class EmployeeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_number' => $this->document_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            // US2-1: se informa el estado para señalar cargos o compañías
            // inactivos que el empleado conserva.
            'position' => $this->whenLoaded('position', fn () => $this->position->only(['id', 'name', 'is_active'])),
            'company' => $this->whenLoaded('company', fn () => $this->company?->only(['id', 'name', 'is_active'])),
            'phone' => $this->phone,
            'email' => $this->email,
            'hire_date' => $this->hire_date?->toDateString(),
            'shirt_size' => $this->shirt_size,
            'pants_size' => $this->pants_size,
            'boot_size' => $this->boot_size,
            'glove_size' => $this->glove_size,
            'is_active' => $this->is_active,
            // RN-015: usuario del sistema vinculado, si lo hay. E-4: el username
            // (identificador de ingreso) solo se expone a quien puede ver
            // usuarios; los demás solo saben si tiene cuenta.
            'user' => $this->whenLoaded('user', fn () => $request->user()?->can('viewAny', User::class)
                ? $this->user?->only(['id', 'username'])
                : null),
            // when() y no whenLoaded(): whenLoaded() devuelve null sin evaluar el
            // closure cuando la relación cargada es null.
            'has_user' => $this->when($this->relationLoaded('user'), fn () => $this->user !== null),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
