<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ubicaciones (FR-004): lista plana, sin ubicación padre (RN-002); cada
     * una tiene un tipo de ubicación. FK restrict (D-010). Nombre único sin
     * distinguir mayúsculas (FR-006).
     */
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_type_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('location_type_id');
        });

        DB::statement('CREATE UNIQUE INDEX locations_name_lower_unique ON locations (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
