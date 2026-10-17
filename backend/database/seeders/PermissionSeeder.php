<?php

namespace Database\Seeders;

use App\Enums\Permission as PermissionCode;
use App\Models\Permission;
use Illuminate\Database\Seeder;

/**
 * Sincroniza la tabla `permissions` con el catálogo fijo del enum (D-002,
 * RN-009). Idempotente.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PermissionCode::cases() as $permission) {
            Permission::query()->updateOrCreate(
                ['code' => $permission->value],
                [
                    'module' => $permission->module(),
                    'action' => $permission->action(),
                    'name' => $permission->label(),
                ],
            );
        }
    }
}
