<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Administrador inicial. En producción la contraseña DEBE venir de
        // ADMIN_INITIAL_PASSWORD (nunca se genera ni se imprime: quedaría en
        // los logs del proveedor). Fuera de producción, si falta, se genera
        // una aleatoria y se muestra una sola vez en consola.
        $configuredPassword = config('app.admin_initial_password');

        if (! $configuredPassword && app()->isProduction() && ! User::query()->where('username', 'admin')->exists()) {
            throw new RuntimeException('Define ADMIN_INITIAL_PASSWORD para crear el administrador inicial.');
        }

        $password = $configuredPassword ?: Str::password(16);

        $admin = User::query()->firstOrCreate(
            ['username' => 'admin'],
            [
                'name' => 'Administrador',
                'email' => 'admin@activosb31.local',
                'password' => $password,
                'is_active' => true,
            ],
        );

        if ($admin->wasRecentlyCreated && ! $configuredPassword) {
            $this->command?->warn("Usuario 'admin' creado con contraseña generada: {$password}");
        }
    }
}
