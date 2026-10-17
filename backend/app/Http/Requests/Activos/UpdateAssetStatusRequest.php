<?php

namespace App\Http\Requests\Activos;

use App\Enums\AssetStatus;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cambio manual de estado (US2, D-001): destino Disponible, En
 * mantenimiento o Dado de baja; la baja exige motivo (≤ 500). "Asignado"
 * solo lo pone Entregas. Requiere activos.gestionar (FR-007).
 */
class UpdateAssetStatusRequest extends FormRequest
{
    public const string ASSIGNED_NOT_ALLOWED_MESSAGE = 'El estado Asignado solo lo ponen y quitan las entregas.';

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('asset'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $reason = trim($this->input('reason'));
            $this->merge(['reason' => $reason === '' ? null : $reason]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'bail', 'required', Rule::enum(AssetStatus::class),
                function (string $attribute, mixed $value, Closure $fail) {
                    if ($value === AssetStatus::Assigned->value) {
                        $fail(self::ASSIGNED_NOT_ALLOWED_MESSAGE);
                    }
                },
            ],
            'reason' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->input('status') === AssetStatus::Retired->value)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'El motivo es obligatorio para dar de baja el activo.',
        ];
    }

    public function targetStatus(): AssetStatus
    {
        return AssetStatus::from($this->validated('status'));
    }
}
