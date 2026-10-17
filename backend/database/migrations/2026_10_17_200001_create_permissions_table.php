<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo fijo de permisos '<modulo>.ver' | '<modulo>.gestionar'
     * (RN-009), sincronizado por PermissionSeeder desde App\Enums\Permission.
     */
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('module', 40);
            $table->string('action', 20);
            $table->string('name', 120);
            $table->timestamps();
        });

        DB::statement("ALTER TABLE permissions ADD CONSTRAINT permissions_action_check CHECK (action IN ('ver', 'gestionar'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
