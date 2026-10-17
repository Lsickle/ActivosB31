<?php

namespace App\Http\Requests;

use App\Enums\SecurityEventType;
use App\Models\SecurityEvent;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filtros de la bitácora (FR-008, D-006). Las fechas son días calendario de
 * America/Bogota, inclusivas.
 */
class ListSecurityEventsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', SecurityEvent::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'event' => ['nullable', Rule::enum(SecurityEventType::class)],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
