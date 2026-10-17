<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rol de la matriz de permisos (RN-009). Los precargados (RN-010) tienen
 * `code` y `is_system`; los creados desde la aplicación tienen `code` NULL.
 */
#[Fillable(['name', 'description'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const string ADMIN = 'admin';

    public const string ALMACENISTA = 'almacenista';

    public const string CONSULTA = 'consulta';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_system' => false,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return BelongsToMany<Permission, $this>
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Definición para withCount/loadCount: `users_count` cuenta solo usuarios
     * activos (RN-016), el mismo criterio que impide desactivar un rol en uso.
     *
     * @return array<string, \Closure>
     */
    public static function activeUsersCount(): array
    {
        return ['users' => fn ($query) => $query->where('is_active', true)];
    }

    /**
     * El Administrador tiene todos los permisos y no se pueden quitar (RN-010).
     */
    public function isAdmin(): bool
    {
        return $this->code === self::ADMIN;
    }
}
