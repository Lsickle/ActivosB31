<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categorías de activo (FR-001). Sin softDeletes: no se borran, se
     * desactivan (RN-003). Nombre único sin distinguir mayúsculas (FR-006).
     */
    public function up(): void
    {
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE UNIQUE INDEX asset_categories_name_lower_unique ON asset_categories (lower(name))');
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_categories');
    }
};
