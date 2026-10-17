<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Empleados de la estación (RN-021). No se borran, se desactivan: sin
     * softDeletes y FK restrict. Documento único por tipo + número (FR-002,
     * D-002); el número se guarda solo con [A-Z0-9].
     */
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('document_type', 5);
            $table->string('document_number', 20);
            $table->string('first_name', 80);
            $table->string('last_name', 80);
            $table->foreignId('position_id')->constrained()->restrictOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('phone', 30)->nullable();
            $table->string('email', 255)->nullable();
            $table->date('hire_date')->nullable();
            $table->string('shirt_size', 10)->nullable();
            $table->string('pants_size', 10)->nullable();
            $table->string('boot_size', 10)->nullable();
            $table->string('glove_size', 10)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['document_type', 'document_number'], 'employees_document_type_number_unique');
            $table->index('position_id');
            $table->index('company_id');
            // FR-001: orden del listado por apellidos y nombres.
            $table->index(['last_name', 'first_name', 'id']);
        });

        DB::statement("ALTER TABLE employees ADD CONSTRAINT employees_document_type_check CHECK (document_type IN ('CC', 'CE', 'TI', 'PA', 'PPT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
