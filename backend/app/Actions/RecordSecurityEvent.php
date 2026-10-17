<?php

namespace App\Actions;

use App\Enums\SecurityEventType;
use App\Models\Role;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Registra un evento en la bitácora de seguridad (A-1, D-001). Se llama
 * explícitamente desde cada operación auditada, dentro de su misma
 * transacción (FR-009): si la operación falla, el evento no queda.
 *
 * FR-006: IP y navegador salen de la petición actual; el navegador se recorta
 * a 255 caracteres. Nunca se pasan contraseñas ni tokens en $metadata.
 * Fuera de HTTP (comandos de consola, A-2) se usa withoutRequest(): IP y
 * navegador quedan en null en vez de los valores ficticios de la petición
 * que Laravel crea para la consola.
 */
class RecordSecurityEvent
{
    private const int MAX_TEXT_LENGTH = 255;

    public function __construct(private ?Request $request) {}

    public static function withoutRequest(): self
    {
        return new self(null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function handle(
        SecurityEventType $type,
        ?User $actor,
        ?User $target = null,
        ?Role $role = null,
        array $metadata = [],
        ?string $loginAttempted = null,
    ): SecurityEvent {
        return SecurityEvent::query()->create([
            'event' => $type,
            'actor_id' => $actor?->getKey(),
            'target_user_id' => $target?->getKey(),
            'target_role_id' => $role?->getKey(),
            'login_attempted' => self::truncate($loginAttempted),
            'ip_address' => $this->request?->ip(),
            'user_agent' => self::truncate($this->request?->userAgent()),
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    private static function truncate(?string $value): ?string
    {
        return $value === null ? null : mb_substr($value, 0, self::MAX_TEXT_LENGTH);
    }
}
