<?php

namespace App\Http\Requests\Activos;

use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * Alta (POST) o edición parcial (PATCH) de un plan de mantenimiento
 * (RN-029, RN-030, D-003). La anticipación vacía vuelve a los 30 días por
 * defecto. Requiere activos.gestionar (FR-007).
 */
class SaveMaintenancePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('asset'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim((string) preg_replace('/\s+/u', ' ', $this->input('name')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $sometimes = $this->isMethod('PATCH') ? ['sometimes'] : [];
        $today = Carbon::now(Asset::LOCAL_TIMEZONE)->toDateString();

        return [
            'name' => [...$sometimes, 'required', 'string', 'max:120'],
            'interval_months' => [...$sometimes, 'required', 'integer', 'min:1', 'max:600'],
            'last_done_on' => [...$sometimes, 'bail', 'nullable', 'date_format:Y-m-d', "before_or_equal:{$today}"],
            'alert_days' => [...$sometimes, 'nullable', 'integer', 'min:0', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'last_done_on.before_or_equal' => 'La fecha del último mantenimiento no puede ser futura.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function planData(): array
    {
        $data = $this->validated();

        if (array_key_exists('alert_days', $data) && $data['alert_days'] === null) {
            $data['alert_days'] = AssetMaintenancePlan::DEFAULT_ALERT_DAYS;
        }

        return $data;
    }
}
