<?php

namespace Database\Factories;

use App\Models\Permission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Los permisos reales salen de App\Enums\Permission vía PermissionSeeder;
 * esta factory solo genera filas ajenas al catálogo para pruebas.
 *
 * @extends Factory<Permission>
 */
class PermissionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $module = fake()->unique()->lexify('modulo????');

        return [
            'code' => $module.'.ver',
            'module' => $module,
            'action' => 'ver',
            'name' => 'Ver '.$module,
        ];
    }
}
