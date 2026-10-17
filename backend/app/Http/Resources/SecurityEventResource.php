<?php

namespace App\Http\Resources;

use App\Models\SecurityEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Evento de la bitácora (FR-006, contrato de 003). La fecha se entrega en
 * hora de Bogotá con su desfase (p. ej. 2026-10-17T15:04:05-05:00).
 *
 * @mixin SecurityEvent
 */
class SecurityEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event->value,
            'event_label' => $this->event->label(),
            'created_at' => $this->created_at->setTimezone(SecurityEvent::DISPLAY_TIMEZONE)->toIso8601String(),
            'actor' => $this->actor?->only(['id', 'name', 'username']),
            'target_user' => $this->targetUser?->only(['id', 'name', 'username']),
            'target_role' => $this->targetRole?->only(['id', 'name']),
            'login_attempted' => $this->login_attempted,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'metadata' => $this->metadata,
        ];
    }
}
