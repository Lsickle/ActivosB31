<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conteos físicos por ubicación (RN-027, D-004). Un solo conteo abierto
     * por ubicación (índice único parcial). No se borran.
     */
    public function up(): void
    {
        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->index()->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('open');
            $table->string('notes', 500)->nullable();
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->timestampTz('opened_at', 6)->useCurrent();
            $table->timestampTz('closed_at', 6)->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE inventory_counts ADD CONSTRAINT inventory_counts_status_check
                CHECK (status IN ('open', 'closed'));
            ALTER TABLE inventory_counts ADD CONSTRAINT inventory_counts_closed_check
                CHECK ((status = 'closed') = (closed_at IS NOT NULL AND closed_by IS NOT NULL));
            CREATE UNIQUE INDEX inventory_counts_one_open_per_location
                ON inventory_counts (location_id) WHERE status = 'open';
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_counts');
    }
};
