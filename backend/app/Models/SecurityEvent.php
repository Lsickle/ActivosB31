<?php

namespace App\Models;

use App\Enums\SecurityEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evento de la bitácora de seguridad (A-1, FR-005..FR-007). Solo inserción:
 * el modelo rechaza modificar o borrar, y un trigger de PostgreSQL rechaza
 * UPDATE/DELETE/TRUNCATE aunque se intenten con SQL directo (D-002). Se crea
 * con App\Actions\RecordSecurityEvent.
 */
#[Fillable([
    'event', 'actor_id', 'target_user_id', 'target_role_id',
    'login_attempted', 'ip_address', 'user_agent', 'metadata',
])]
class SecurityEvent extends Model
{
    public const string IMMUTABLE_MESSAGE = 'La bitácora de seguridad es de solo inserción.';

    public const ?string UPDATED_AT = null;

    /**
     * Zona en que se muestran las fechas y se interpretan los filtros por
     * día (D-006); la aplicación guarda en UTC.
     */
    public const string DISPLAY_TIMEZONE = 'America/Bogota';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => SecurityEventType::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * FR-007: un evento ya guardado no se modifica.
     *
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(self::IMMUTABLE_MESSAGE);
        }

        return parent::save($options);
    }

    /**
     * FR-007: los eventos no se borran.
     */
    public function delete(): ?bool
    {
        throw new LogicException(self::IMMUTABLE_MESSAGE);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_user_id');
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function targetRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'target_role_id');
    }
}
