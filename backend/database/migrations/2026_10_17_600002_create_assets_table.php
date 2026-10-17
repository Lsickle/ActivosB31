<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Activos serializados (RN-001, RN-007, FR-001). No se borran (sin
     * softDeletes, FR-001): se dan de baja (RN-005). FK RESTRICT.
     *
     * - RN-008 / D-006: placa obligatoria, única sin distinguir mayúsculas.
     * - RN-005: cuatro estados; "assigned" solo vía Entregas (D-001).
     * - RN-039: el poseedor existe si y solo si el activo está asignado.
     */
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_type_id')->index()->constrained()->restrictOnDelete();
            $table->string('plate', 50);
            $table->string('serial', 100)->nullable();
            $table->string('brand', 80)->nullable();
            $table->string('model', 80)->nullable();
            $table->date('acquisition_date')->nullable();
            $table->decimal('acquisition_value', 14, 2)->nullable();
            $table->smallInteger('useful_life_months')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('status', 20)->default('available')->index();
            $table->foreignId('location_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->foreignId('holder_employee_id')->nullable()->index()->constrained('employees')->restrictOnDelete();
            $table->string('photo_path', 255)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX assets_plate_lower_unique ON assets (lower(plate));
            ALTER TABLE assets ADD CONSTRAINT assets_status_check
                CHECK (status IN ('available', 'assigned', 'maintenance', 'retired'));
            ALTER TABLE assets ADD CONSTRAINT assets_holder_status_check
                CHECK ((status = 'assigned') = (holder_employee_id IS NOT NULL));
            ALTER TABLE assets ADD CONSTRAINT assets_acquisition_value_check
                CHECK (acquisition_value IS NULL OR acquisition_value >= 0);
            ALTER TABLE assets ADD CONSTRAINT assets_useful_life_months_check
                CHECK (useful_life_months IS NULL OR useful_life_months > 0);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
