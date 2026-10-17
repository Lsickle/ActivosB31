<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Planes de mantenimiento por activo (RN-029): periodicidad en meses,
     * último realizado y próximo calculado (D-003); anticipación de alerta,
     * 30 días por defecto (RN-030).
     */
    public function up(): void
    {
        Schema::create('asset_maintenance_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->index()->constrained();
            $table->string('name', 120);
            $table->smallInteger('interval_months');
            $table->date('last_done_on')->nullable();
            $table->date('next_due_on')->index();
            $table->smallInteger('alert_days')->default(30);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE asset_maintenance_plans ADD CONSTRAINT asset_maintenance_plans_interval_months_check
                CHECK (interval_months > 0);
            ALTER TABLE asset_maintenance_plans ADD CONSTRAINT asset_maintenance_plans_alert_days_check
                CHECK (alert_days >= 0);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_maintenance_plans');
    }
};
