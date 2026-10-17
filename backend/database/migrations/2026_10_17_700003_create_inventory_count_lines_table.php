<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Renglones de un conteo físico (RN-027, RN-037, D-004):
     * - de cantidad: asset_type_id + expected_quantity / counted_quantity;
     * - de activo serializado: asset_id (+ el asset_type_id del activo) y found.
     * Un renglón por tipo de cantidad y uno por activo en cada conteo.
     */
    public function up(): void
    {
        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('count_id')->constrained('inventory_counts');
            $table->foreignId('asset_type_id')->nullable()->constrained();
            $table->foreignId('asset_id')->nullable()->index()->constrained();
            $table->integer('expected_quantity')->nullable();
            $table->integer('counted_quantity')->nullable();
            $table->boolean('found')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE inventory_count_lines ADD CONSTRAINT inventory_count_lines_kind_check CHECK (
                (asset_id IS NULL AND asset_type_id IS NOT NULL AND expected_quantity IS NOT NULL AND found IS NULL)
                OR (asset_id IS NOT NULL AND expected_quantity IS NULL AND counted_quantity IS NULL)
            );
            ALTER TABLE inventory_count_lines ADD CONSTRAINT inventory_count_lines_quantities_check
                CHECK ((expected_quantity IS NULL OR expected_quantity >= 0) AND (counted_quantity IS NULL OR counted_quantity >= 0));
            CREATE UNIQUE INDEX inventory_count_lines_count_type_unique
                ON inventory_count_lines (count_id, asset_type_id) WHERE asset_id IS NULL;
            CREATE UNIQUE INDEX inventory_count_lines_count_asset_unique
                ON inventory_count_lines (count_id, asset_id) WHERE asset_id IS NOT NULL;
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
    }
};
