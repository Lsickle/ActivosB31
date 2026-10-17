<?php

namespace App\Http\Requests\Activos;

use App\Models\Asset;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Registro de un mantenimiento realizado (FR-005): fecha (no futura),
 * descripción, quién lo hizo y costo opcionales; el plan, si se indica, debe
 * ser del mismo activo. Requiere activos.gestionar (FR-007).
 */
class StoreMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->asset());
    }

    protected function prepareForValidation(): void
    {
        foreach (['description', 'performed_by'] as $field) {
            if (is_string($this->input($field))) {
                $value = trim($this->input($field));
                $this->merge([$field => $value === '' ? null : $value]);
            }
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $today = Carbon::now(Asset::LOCAL_TIMEZONE)->toDateString();

        return [
            'plan_id' => [
                'nullable', 'integer',
                Rule::exists('asset_maintenance_plans', 'id')->where('asset_id', $this->asset()->id),
            ],
            'performed_on' => ['bail', 'required', 'date_format:Y-m-d', "before_or_equal:{$today}"],
            'description' => ['required', 'string', 'max:500'],
            'performed_by' => ['nullable', 'string', 'max:120'],
            'cost' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'performed_on.before_or_equal' => 'La fecha del mantenimiento no puede ser futura.',
        ];
    }

    public function asset(): Asset
    {
        return $this->route('asset');
    }
}
