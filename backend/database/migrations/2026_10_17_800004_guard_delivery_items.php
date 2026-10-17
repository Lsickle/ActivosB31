<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * M-2 (revisión de seguridad de 007): guarda de delivery_items. La tabla
     * no es de solo inserción porque returned_quantity acumula lo devuelto
     * (D-004), pero eso es lo ÚNICO que puede cambiar:
     *
     * - UPDATE: solo si todas las demás columnas quedan iguales (se compara
     *   la fila completa sin returned_quantity, así una columna futura queda
     *   protegida sin tocar la función) y returned_quantity no disminuye.
     * - DELETE: rechazado.
     * - TRUNCATE: rechazado con prevent_row_changes() (005).
     *
     * Las limpiezas de pruebas con datos confirmados desactivan el trigger
     * dentro de su transacción, como en las demás tablas de solo inserción.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION delivery_items_guard() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE'
                    AND (to_jsonb(NEW) - 'returned_quantity') = (to_jsonb(OLD) - 'returned_quantity')
                    AND NEW.returned_quantity >= OLD.returned_quantity THEN
                    RETURN NEW;
                END IF;

                RAISE EXCEPTION 'En delivery_items solo se permite aumentar returned_quantity (% rechazado).', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER delivery_items_guard_rows
                BEFORE UPDATE OR DELETE ON delivery_items
                FOR EACH ROW EXECUTE FUNCTION delivery_items_guard();

            CREATE TRIGGER delivery_items_immutable_truncate
                BEFORE TRUNCATE ON delivery_items
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_row_changes();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS delivery_items_immutable_truncate ON delivery_items;
            DROP TRIGGER IF EXISTS delivery_items_guard_rows ON delivery_items;
            DROP FUNCTION IF EXISTS delivery_items_guard();
            SQL);
    }
};
