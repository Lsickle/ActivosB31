<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Roles de la matriz de permisos (RN-009, RN-010). `code` identifica los
     * roles precargados ('admin', 'almacenista', 'consulta'); es NULL en los
     * roles creados desde la aplicación.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name', 80);
            $table->string('description', 500)->nullable();
            // Precargado: no se puede desactivar (FR-013).
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX roles_name_lower_unique ON roles (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
