<?php

namespace App\Http\Requests\Configuracion;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Cambio de ajustes generales (D-005 de 005, FR-006): anticipación global de
 * vencimientos entre 0 y 365 días (RN-030). Requiere configuracion.gestionar.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can(Permission::ConfiguracionGestionar->value);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'expiry_alert_days' => ['required', 'integer', 'min:0', 'max:365'],
        ];
    }
}
