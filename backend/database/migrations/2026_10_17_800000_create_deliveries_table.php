<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Entregas (007, FR-001, RN-023, RN-024). SOLO INSERCIÓN: triggers con
     * prevent_row_changes() (005); no se editan ni se borran (US1-6).
     *
     * - D-001: el consecutivo ENT-000001 lo asigna la secuencia
     *   deliveries_code_seq como valor por defecto de code.
     * - RN-023: el préstamo lleva fecha esperada de devolución y la dotación
     *   no (deliveries_due_on_check).
     * - RN-024 / D-002: signature_path apunta al PNG de la firma en el disco
     *   privado ASSETS_DISK (signatures/{id}.png). El id se reserva antes de
     *   insertar para no tener que actualizar la fila.
     */
    public function up(): void
    {
        DB::statement('CREATE SEQUENCE deliveries_code_seq');

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()
                ->default(DB::raw("'ENT-' || lpad(nextval('deliveries_code_seq')::text, 6, '0')"));
            $table->foreignId('employee_id')->index()->constrained();
            $table->string('kind', 20);
            $table->date('due_on')->nullable();
            $table->foreignId('from_location_id')->index()->constrained('locations');
            $table->string('signature_path', 255);
            $table->text('notes')->nullable();
            $table->foreignId('delivered_by')->constrained('users');
            $table->timestampTz('delivered_at');
            $table->timestampTz('created_at')->useCurrent();

            // Listado: más recientes primero (US3).
            $table->index(['delivered_at', 'id']);
        });

        DB::unprepared(<<<'SQL'
            ALTER SEQUENCE deliveries_code_seq OWNED BY deliveries.code;

            ALTER TABLE deliveries ADD CONSTRAINT deliveries_kind_check
                CHECK (kind IN ('dotacion', 'prestamo'));
            ALTER TABLE deliveries ADD CONSTRAINT deliveries_due_on_check
                CHECK ((kind = 'prestamo') = (due_on IS NOT NULL));

            CREATE TRIGGER deliveries_immutable_rows
                BEFORE UPDATE OR DELETE ON deliveries
                FOR EACH ROW EXECUTE FUNCTION prevent_row_changes();

            CREATE TRIGGER deliveries_immutable_truncate
                BEFORE TRUNCATE ON deliveries
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_changes();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deliveries');
        DB::statement('DROP SEQUENCE IF EXISTS deliveries_code_seq');
    }
};
