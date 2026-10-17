<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    private const string INITIAL_ADMIN_USERNAME = 'admin';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // M-2: el administrador inicial solo se crea si no existe ningún
        // usuario con el rol Administrador; los existentes no se tocan.
        $mustCreateAdmin = ! $this->anyAdminExists();
        $configuredPassword = config('app.admin_initial_password');

        // En producción la contraseña DEBE venir de ADMIN_INITIAL_PASSWORD
        // (nunca se genera ni se imprime: quedaría en los logs del proveedor).
        // Fuera de producción, si falta, se genera una aleatoria y se muestra
        // una sola vez en consola.
        if ($mustCreateAdmin && ! $configuredPassword && app()->isProduction()) {
            throw new RuntimeException('Define ADMIN_INITIAL_PASSWORD para crear el administrador inicial.');
        }

        $this->call([PermissionSeeder::class, RoleSeeder::class]);

        // RN-012 / D-008: catálogos de Configuración en todos los entornos;
        // solo carga las tablas vacías.
        $this->call(ConfigurationSeeder::class);

        if (! $mustCreateAdmin) {
            return;
        }

        if (User::query()->whereRaw('lower(username) = ?', [self::INITIAL_ADMIN_USERNAME])->exists()) {
            throw new RuntimeException(
                "Ya existe el usuario 'admin' sin rol Administrador y no hay ningún Administrador: asígnale el rol manualmente.",
            );
        }

        $password = $configuredPassword ?: Str::password(16);

        // FR-004: rol Administrador. M-3 / RN-014: debe cambiar la contraseña
        // inicial en su primer ingreso.
        User::query()->forceCreate([
            'name' => 'Administrador',
            'username' => self::INITIAL_ADMIN_USERNAME,
            'email' => 'admin@activosb31.local',
            'password' => $password,
            'is_active' => true,
            'must_change_password' => true,
            'role_id' => Role::query()->where('code', Role::ADMIN)->value('id'),
        ]);

        if (! $configuredPassword) {
            $this->command?->warn("Usuario 'admin' creado con contraseña generada: {$password}");
        }
    }

    private function anyAdminExists(): bool
    {
        return User::query()
            ->whereHas('role', fn ($query) => $query->where('code', Role::ADMIN))
            ->exists();
    }
}
