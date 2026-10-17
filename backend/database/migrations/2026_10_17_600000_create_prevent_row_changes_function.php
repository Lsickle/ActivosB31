<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Función reutilizable para tablas de SOLO INSERCIÓN (historial de activos,
     * mantenimientos realizados y, en 006, movimientos de inventario). Mismo
     * patrón que prevent_security_events_change() de 003: cada tabla le
     * conecta un trigger BEFORE UPDATE OR DELETE por fila y uno BEFORE
     * TRUNCATE por sentencia. DROP TABLE sigue funcionando.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_row_changes() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La tabla % es de solo inserción (% rechazado).', TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_row_changes()');
    }
};
