<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mantenimientos realizados (FR-005). SOLO INSERCIÓN (SC-003): triggers
     * con prevent_row_changes().
     */
    public function up(): void
    {
        Schema::create('asset_maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained();
            $table->foreignId('plan_id')->nullable()->index()->constrained('asset_maintenance_plans');
            $table->date('performed_on');
            $table->string('description', 500);
            $table->string('performed_by', 120)->nullable();
            $table->decimal('cost', 14, 2)->nullable();
            $table->foreignId('user_id')->constrained();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['asset_id', 'performed_on']);
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE asset_maintenance_records ADD CONSTRAINT asset_maintenance_records_cost_check
                CHECK (cost IS NULL OR cost >= 0);

            CREATE TRIGGER asset_maintenance_records_immutable_rows
                BEFORE UPDATE OR DELETE ON asset_maintenance_records
                FOR EACH ROW EXECUTE FUNCTION prevent_row_changes();

            CREATE TRIGGER asset_maintenance_records_immutable_truncate
                BEFORE TRUNCATE ON asset_maintenance_records
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_records');
    }
};
