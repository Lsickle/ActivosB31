<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bitácora de seguridad (A-1, FR-005..FR-007). Solo inserción (D-002): un
     * trigger rechaza UPDATE/DELETE por fila y TRUNCATE por sentencia, de modo
     * que ni una consulta directa del usuario de la aplicación pueda alterarla.
     * DROP TABLE (migrate:fresh en desarrollo y tests) sigue funcionando.
     */
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 50)->index();
            $table->foreignId('actor_id')->nullable()->index()->constrained('users');
            $table->foreignId('target_user_id')->nullable()->index()->constrained('users');
            $table->foreignId('target_role_id')->nullable()->constrained('roles');
            $table->string('login_attempted', 255)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION prevent_security_events_change() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'La bitácora de seguridad es de solo inserción (% rechazado).', TG_OP
                    USING ERRCODE = 'insufficient_privilege';
            END;
            $$;

            CREATE TRIGGER security_events_immutable_rows
                BEFORE UPDATE OR DELETE ON security_events
                FOR EACH ROW EXECUTE FUNCTION prevent_security_events_change();

            CREATE TRIGGER security_events_immutable_truncate
                BEFORE TRUNCATE ON security_events
                FOR EACH STATEMENT EXECUTE FUNCTION prevent_security_events_change();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
        DB::unprepared('DROP FUNCTION IF EXISTS prevent_security_events_change()');
    }
};
