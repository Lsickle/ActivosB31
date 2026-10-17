<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compañías o turnos de los empleados (RN-036), catálogo de
     * Configuración sin precarga (D-001). Sin softDeletes:
     * se desactivan (RN-003). Nombre único sin distinguir mayúsculas (FR-004).
     */
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX companies_name_lower_unique ON companies (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
