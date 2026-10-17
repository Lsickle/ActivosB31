<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 005 y 006 dejaron delivery_id sin FK hasta que existiera `deliveries`
     * (esquema-bd). Agregar una restricción no modifica filas, así que los
     * triggers de solo inserción no intervienen.
     */
    public function up(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreign('delivery_id')->references('id')->on('deliveries');
        });

        Schema::table('asset_events', function (Blueprint $table) {
            $table->foreign('delivery_id')->references('id')->on('deliveries');
        });
    }

    public function down(): void
    {
        Schema::table('asset_events', function (Blueprint $table) {
            $table->dropForeign(['delivery_id']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropForeign(['delivery_id']);
        });
    }
};
