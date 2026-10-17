<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * RN-028 / D-008: stock mínimo opcional, entero ≥ 0, solo en tipos por
     * cantidad (los serializados no lo llevan).
     */
    public function up(): void
    {
        Schema::table('asset_types', function (Blueprint $table) {
            $table->integer('min_stock')->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE asset_types ADD CONSTRAINT asset_types_min_stock_check
                CHECK (min_stock IS NULL OR (min_stock >= 0 AND tracking_mode = 'quantity'))
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE asset_types DROP CONSTRAINT IF EXISTS asset_types_min_stock_check');

        Schema::table('asset_types', function (Blueprint $table) {
            $table->dropColumn('min_stock');
        });
    }
};
