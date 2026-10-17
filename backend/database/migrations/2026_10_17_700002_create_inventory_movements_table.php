<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Movimientos de inventario (006, FR-001, D-002). SOLO INSERCIÓN: triggers
     * con prevent_row_changes() (005); un error se corrige con un movimiento
     * inverso. delivery_id queda sin FK hasta que 007 cree `deliveries`.
     *
     * inventory_movements_locations_check fija qué ubicaciones lleva cada
     * clase (D-002): entry/return → destino; exit/delivery → origen;
     * transfer → ambas, distintas; adjustment → exactamente una; loss →
     * ninguna (RN-038).
     */
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->foreignId('asset_type_id')->index()->constrained();
            $table->integer('quantity');
            $table->foreignId('from_location_id')->nullable()->index()->constrained('locations');
            $table->foreignId('to_location_id')->nullable()->index()->constrained('locations');
            $table->unsignedBigInteger('delivery_id')->nullable()->index();
            $table->foreignId('count_id')->nullable()->index()->constrained('inventory_counts');
            $table->string('reason', 500)->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestampTz('created_at', 6)->useCurrent();

            // Historial del más reciente al más antiguo (FR-003).
            $table->index(['created_at', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_type_check
                CHECK (type IN ('entry', 'exit', 'transfer', 'adjustment', 'delivery', 'return', 'loss'));
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_quantity_check
                CHECK (quantity > 0);
            ALTER TABLE inventory_movements ADD CONSTRAINT inventory_movements_locations_check CHECK (
                (type IN ('entry', 'return') AND from_location_id IS NULL AND to_location_id IS NOT NULL)
                OR (type IN ('exit', 'delivery') AND from_location_id IS NOT NULL AND to_location_id IS NULL)
                OR (type = 'transfer' AND from_location_id IS NOT NULL AND to_location_id IS NOT NULL
                    AND from_location_id <> to_location_id)
                OR (type = 'adjustment' AND (from_location_id IS NULL) <> (to_location_id IS NULL))
                OR (type = 'loss' AND from_location_id IS NULL AND to_location_id IS NULL)
            );

            CREATE TRIGGER inventory_movements_immutable_rows
                BEFORE UPDATE OR DELETE ON inventory_movements
                FOR EACH ROW EXECUTE FUNCTION prevent_row_changes();

            CREATE TRIGGER inventory_movements_immutable_truncate
                BEFORE TRUNCATE ON inventory_movements
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
