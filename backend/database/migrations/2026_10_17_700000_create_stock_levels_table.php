<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Existencias de los artículos por cantidad (RN-007) por tipo y ubicación
     * (006, FR-002). Solo las modifica App\Actions\Inventario\RecordStockMovement
     * (D-001); el CHECK >= 0 es la última defensa contra existencias negativas.
     */
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->foreignId('asset_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->index()->constrained()->restrictOnDelete();
            $table->integer('quantity')->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->primary(['asset_type_id', 'location_id']);
        });

        DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_quantity_check CHECK (quantity >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
