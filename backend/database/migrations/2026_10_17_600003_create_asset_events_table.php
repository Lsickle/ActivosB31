<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Historial del activo (FR-004, US3, D-002). SOLO INSERCIÓN: triggers con
     * prevent_row_changes(). delivery_id queda sin FK hasta que 007 cree
     * `deliveries`. changes guarda los campos editados en "updated"
     * ({campo:{from,to}}); el motivo de baja va en notes.
     */
    public function up(): void
    {
        Schema::create('asset_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->string('event', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->foreignId('from_location_id')->nullable()->constrained('locations');
            $table->foreignId('to_location_id')->nullable()->constrained('locations');
            $table->foreignId('employee_id')->nullable()->index()->constrained();
            $table->unsignedBigInteger('delivery_id')->nullable()->index();
            $table->foreignId('user_id')->constrained();
            $table->text('notes')->nullable();
            $table->jsonb('changes')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            // Historial de un activo, del más reciente al más antiguo.
            $table->index(['asset_id', 'created_at', 'id']);
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER asset_events_immutable_rows
                BEFORE UPDATE OR DELETE ON asset_events
                FOR EACH ROW EXECUTE FUNCTION prevent_row_changes();

            CREATE TRIGGER asset_events_immutable_truncate
                BEFORE TRUNCATE ON asset_events
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');
    }
};
