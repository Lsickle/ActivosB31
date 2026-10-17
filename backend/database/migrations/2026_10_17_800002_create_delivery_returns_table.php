<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Devoluciones (FR-003, RN-026, RN-038, D-004). SOLO INSERCIÓN: triggers
     * con prevent_row_changes().
     *
     * - good: vuelve a una ubicación (obligatoria).
     * - damaged: el serializado queda En mantenimiento en una ubicación; lo
     *   dañado por cantidad es pérdida sin ubicación (RN-038).
     * - lost: sin ubicación y con observación obligatoria.
     */
    public function up(): void
    {
        Schema::create('delivery_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_item_id')->index()->constrained();
            $table->integer('quantity');
            $table->string('condition', 20);
            $table->foreignId('to_location_id')->nullable()->index()->constrained('locations');
            $table->text('notes')->nullable();
            $table->foreignId('received_by')->constrained('users');
            $table->timestampTz('returned_at');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE delivery_returns ADD CONSTRAINT delivery_returns_quantity_check
                CHECK (quantity > 0);
            ALTER TABLE delivery_returns ADD CONSTRAINT delivery_returns_condition_check
                CHECK (condition IN ('good', 'damaged', 'lost'));
            ALTER TABLE delivery_returns ADD CONSTRAINT delivery_returns_location_check CHECK (
                (condition = 'good' AND to_location_id IS NOT NULL)
                OR (condition = 'damaged')
                OR (condition = 'lost' AND to_location_id IS NULL)
            );
            ALTER TABLE delivery_returns ADD CONSTRAINT delivery_returns_lost_notes_check
                CHECK (condition <> 'lost' OR (notes IS NOT NULL AND btrim(notes) <> ''));

            CREATE TRIGGER delivery_returns_immutable_rows
                BEFORE UPDATE OR DELETE ON delivery_returns
                FOR EACH ROW EXECUTE FUNCTION prevent_row_changes();

            CREATE TRIGGER delivery_returns_immutable_truncate
                BEFORE TRUNCATE ON delivery_returns
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_returns');
    }
};
