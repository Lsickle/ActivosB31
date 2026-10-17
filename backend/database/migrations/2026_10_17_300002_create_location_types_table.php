<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipos de ubicación administrables (RN-004, FR-003). Sin softDeletes
     * (RN-003). Nombre único sin distinguir mayúsculas (FR-006).
     */
    public function up(): void
    {
        Schema::create('location_types', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX location_types_name_lower_unique ON location_types (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('location_types');
    }
};
