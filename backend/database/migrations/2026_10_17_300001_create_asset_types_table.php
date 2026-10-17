<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tipos de activo (FR-002): cada uno pertenece a una categoría (1:N) y
     * define su modo de control (RN-007). FK restrict: no hay borrado (D-010).
     * Nombre único por categoría sin distinguir mayúsculas (FR-006).
     */
    public function up(): void
    {
        Schema::create('asset_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('tracking_mode', 20);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('asset_category_id');
        });

        DB::statement("ALTER TABLE asset_types ADD CONSTRAINT asset_types_tracking_mode_check CHECK (tracking_mode IN ('serialized', 'quantity'))");
        DB::statement('CREATE UNIQUE INDEX asset_types_category_name_lower_unique ON asset_types (asset_category_id, lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_types');
    }
};
