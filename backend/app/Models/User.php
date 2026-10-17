<?php

namespace App\Models;

use App\Enums\Permission as PermissionCode;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

/**
 * Persona que inicia sesión. Independiente de Empleado por ahora (RN-015).
 * Tiene exactamente un rol (RN-013).
 */
#[Fillable(['name', 'username', 'email', 'password', 'is_active', 'role_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Permisos efectivos memorizados por instancia: una sola consulta por
     * request (D-003). Sin caché entre requests, así un cambio en la matriz
     * rige desde la siguiente acción (SC-003).
     *
     * @var list<string>|null
     */
    private ?array $resolvedPermissionCodes = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * FR-008: se guarda en minúsculas (la unicidad no distingue mayúsculas).
     */
    protected function username(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizeIdentifier($value));
    }

    /**
     * FR-008: correo opcional, en minúsculas.
     */
    protected function email(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => self::normalizeIdentifier($value));
    }

    public static function normalizeIdentifier(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = Str::lower(trim($value));

        return $normalized === '' ? null : $normalized;
    }

    public function isAdmin(): bool
    {
        return $this->role?->isAdmin() ?? false;
    }

    /**
     * Códigos de permiso efectivos. El Administrador tiene todos (RN-010); un
     * rol inactivo no concede ninguno.
     *
     * @return list<string>
     */
    public function permissionCodes(): array
    {
        return $this->resolvedPermissionCodes ??= $this->resolvePermissionCodes();
    }

    public function hasPermission(PermissionCode|string $permission): bool
    {
        $code = $permission instanceof PermissionCode ? $permission->value : $permission;

        return in_array($code, $this->permissionCodes(), true);
    }

    /**
     * Descarta los permisos memorizados (p. ej. tras cambiar el rol).
     */
    public function forgetPermissions(): static
    {
        $this->resolvedPermissionCodes = null;

        return $this;
    }

    /**
     * @return list<string>
     */
    private function resolvePermissionCodes(): array
    {
        $role = $this->role;

        if ($role === null || ! $role->is_active) {
            return [];
        }

        if ($role->isAdmin()) {
            return PermissionCode::values();
        }

        $granted = $role->permissions()->pluck('code')->all();

        // Orden estable del catálogo, ignorando filas ajenas al enum y, como
        // segunda barrera de RN-019, los permisos exclusivos del Administrador.
        return array_values(array_map(
            fn (PermissionCode $permission) => $permission->value,
            array_filter(
                PermissionCode::cases(),
                fn (PermissionCode $permission) => ! $permission->isAdminOnly() && in_array($permission->value, $granted, true),
            ),
        ));
    }
}
