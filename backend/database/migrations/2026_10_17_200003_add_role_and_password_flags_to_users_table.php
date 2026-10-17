<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rol único por usuario (RN-013), cambio de contraseña obligatorio
     * (RN-014), correo opcional y unicidad sin distinguir mayúsculas (FR-008).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('password')->constrained('roles');
            $table->index('role_id');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->string('email')->nullable()->change();
            $table->dropUnique('users_username_unique');
            $table->dropUnique('users_email_unique');
        });

        // FR-004: los usuarios existentes (el admin inicial) quedan con el rol
        // Administrador, que se crea aquí si aún no existe; RoleSeeder lo
        // completa con sus permisos.
        if (DB::table('users')->whereNull('role_id')->exists()) {
            $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id')
                ?? DB::table('roles')->insertGetId([
                    'code' => 'admin',
                    'name' => 'Administrador',
                    'description' => 'Todos los permisos; no se pueden quitar.',
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('users')->whereNull('role_id')->update(['role_id' => $adminRoleId]);
        }

        // Los índices únicos de abajo comparan en minúsculas y la aplicación
        // guarda en minúsculas: se normalizan los datos existentes.
        DB::statement('UPDATE users SET username = lower(username), email = lower(email)');

        DB::statement('ALTER TABLE users ALTER COLUMN role_id SET NOT NULL');
        DB::statement('CREATE UNIQUE INDEX users_username_lower_unique ON users (lower(username))');
        DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email)) WHERE email IS NOT NULL');
        // El '@' se reserva para distinguir correo de username en el login.
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_no_at_check CHECK (position('@' in username) = 0)");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_username_no_at_check');
        DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
        DB::statement('DROP INDEX IF EXISTS users_username_lower_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
            $table->dropColumn('must_change_password');
            $table->unique('username');
            $table->unique('email');
        });
    }
};
