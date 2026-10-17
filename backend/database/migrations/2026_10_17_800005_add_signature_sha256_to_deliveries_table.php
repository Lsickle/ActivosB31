<?php

use App\Models\Delivery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * M-3 (revisión de seguridad de 007): huella SHA-256 (hexadecimal en
     * minúsculas) del PNG de la firma, calculada antes de insertar la entrega
     * (RN-024, D-002). Permite detectar si el archivo de la firma se cambió o
     * se perdió (Delivery::signatureIntact()).
     *
     * deliveries es de solo inserción: para rellenar filas previas se
     * desactiva el trigger dentro de la transacción de la migración. La tabla
     * no tiene datos reales todavía; si una fila existente no tiene su archivo
     * de firma:
     * - en local se rellena con 64 ceros (nunca coincide con un SHA-256 real:
     *   signatureIntact() da falso) y se avisa en el log;
     * - en cualquier otro entorno la migración falla (no se inventa una
     *   huella).
     */
    public const string MISSING_FILE_SENTINEL = '0000000000000000000000000000000000000000000000000000000000000000';

    public function up(): void
    {
        DB::statement('ALTER TABLE deliveries ADD COLUMN signature_sha256 char(64)');

        $rows = DB::table('deliveries')->orderBy('id')->get(['id', 'signature_path']);

        if ($rows->isNotEmpty()) {
            DB::statement('ALTER TABLE deliveries DISABLE TRIGGER deliveries_immutable_rows');

            foreach ($rows as $row) {
                DB::table('deliveries')->where('id', $row->id)->update(['signature_sha256' => $this->hashFor($row)]);
            }

            DB::statement('ALTER TABLE deliveries ENABLE TRIGGER deliveries_immutable_rows');
        }

        DB::unprepared(<<<'SQL'
            ALTER TABLE deliveries ALTER COLUMN signature_sha256 SET NOT NULL;
            ALTER TABLE deliveries ADD CONSTRAINT deliveries_signature_sha256_check
                CHECK (signature_sha256 ~ '^[0-9a-f]{64}$');
            SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE deliveries DROP COLUMN IF EXISTS signature_sha256');
    }

    private function hashFor(object $row): string
    {
        $contents = Delivery::signatureDisk()->get($row->signature_path);

        if ($contents !== null) {
            return hash('sha256', $contents);
        }

        if (! app()->environment('local')) {
            throw new RuntimeException("La entrega {$row->id} no tiene su archivo de firma ({$row->signature_path}): no se puede calcular signature_sha256.");
        }

        Log::warning("Entrega {$row->id} sin archivo de firma: signature_sha256 queda con el valor centinela (signatureIntact() = false).");

        return self::MISSING_FILE_SENTINEL;
    }
};
