<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artículos de una entrega (RN-025): un activo serializado (asset_id,
     * cantidad 1) o una cantidad de un tipo por cantidad. returned_quantity
     * acumula lo devuelto (D-004) y es lo único que cambia: esta tabla NO es
     * de solo inserción.
     *
     * delivery_items_pending_asset_unique: un activo no puede estar pendiente
     * en dos entregas a la vez (edge case de la spec), además del estado
     * Asignado del activo.
     */
    public function up(): void
    {
        Schema::create('delivery_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->index()->constrained();
            $table->foreignId('asset_id')->nullable()->index()->constrained();
            $table->foreignId('asset_type_id')->index()->constrained();
            $table->integer('quantity');
            $table->integer('returned_quantity')->default(0);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE delivery_items ADD CONSTRAINT delivery_items_quantity_check
                CHECK (quantity > 0);
            ALTER TABLE delivery_items ADD CONSTRAINT delivery_items_returned_quantity_check
                CHECK (returned_quantity >= 0 AND returned_quantity <= quantity);
            ALTER TABLE delivery_items ADD CONSTRAINT delivery_items_asset_quantity_check
                CHECK (asset_id IS NULL OR quantity = 1);

            CREATE UNIQUE INDEX delivery_items_delivery_asset_unique
                ON delivery_items (delivery_id, asset_id) WHERE asset_id IS NOT NULL;
            CREATE UNIQUE INDEX delivery_items_delivery_type_unique
                ON delivery_items (delivery_id, asset_type_id) WHERE asset_id IS NULL;
            CREATE UNIQUE INDEX delivery_items_pending_asset_unique
                ON delivery_items (asset_id) WHERE asset_id IS NOT NULL AND returned_quantity < quantity;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_items');
    }
};
