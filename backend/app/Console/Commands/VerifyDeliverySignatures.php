<?php

namespace App\Console\Commands;

use App\Actions\RecordSecurityEvent;
use App\Enums\SecurityEventType;
use App\Models\Delivery;
use App\Models\SecurityEvent;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;

/**
 * M-3 (revisión de seguridad de 007/008): verifica que la firma de cada
 * entrega (RN-024 / D-002) siga en el disco ASSETS_DISK con el SHA-256
 * registrado al crearla (Delivery::signatureIntact()).
 *
 * Cada firma alterada o faltante queda en la bitácora como
 * signature_integrity_failed, sin actor ni petición (A-2), con metadata
 * {delivery_id, code, reason: altered|missing}. No se repite el evento de la
 * misma entrega y razón si ya hay uno en las últimas 24 horas. Termina con
 * FAILURE si encuentra algún problema. Programado los domingos a las 03:00
 * (America/Bogota) en routes/console.php.
 */
#[Signature('deliveries:verify-signatures
    {--since= : Solo entregas desde esta fecha (YYYY-MM-DD, día de America/Bogota)}
    {--chunk=200 : Entregas por lote}')]
#[Description('Verifica la integridad de las firmas de las entregas (M-3)')]
class VerifyDeliverySignatures extends Command
{
    private const int DUPLICATE_WINDOW_HOURS = 24;

    /**
     * @var array<string, string>
     */
    private const array REASON_LABELS = ['altered' => 'alterada', 'missing' => 'faltante'];

    public function handle(): int
    {
        $since = $this->option('since');
        $sinceDate = $since === null ? null : $this->parseDate((string) $since);

        if ($since !== null && $sinceDate === null) {
            $this->components->error('La opción --since debe ser una fecha válida con formato YYYY-MM-DD.');

            return self::FAILURE;
        }

        $recordEvent = RecordSecurityEvent::withoutRequest();
        $alreadyReported = $this->recentlyReported();
        $verified = 0;

        /** @var list<array{code: string, reason: string}> $problems */
        $problems = [];

        Delivery::query()
            ->select(['id', 'code', 'signature_path', 'signature_sha256'])
            ->when($sinceDate, fn ($query, CarbonImmutable $date) => $query->where('delivered_at', '>=', $date->utc()))
            ->chunkById(max(1, (int) $this->option('chunk')), function (Collection $deliveries) use (&$verified, &$problems, $alreadyReported, $recordEvent) {
                foreach ($deliveries as $delivery) {
                    $verified++;

                    $reason = $this->problemWith($delivery);

                    if ($reason === null) {
                        continue;
                    }

                    $problems[] = ['code' => $delivery->code, 'reason' => $reason];

                    if (! $alreadyReported->contains("{$delivery->id}:{$reason}")) {
                        $recordEvent->handle(SecurityEventType::SignatureIntegrityFailed, actor: null, metadata: [
                            'delivery_id' => $delivery->id,
                            'code' => $delivery->code,
                            'reason' => $reason,
                        ]);
                    }
                }
            });

        $this->line(sprintf('%d firmas verificadas, %d con problemas.', $verified, count($problems)));

        foreach ($problems as $problem) {
            $this->line("  {$problem['code']}: ".self::REASON_LABELS[$problem['reason']]);
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Inicio del día $value en America/Bogota, o null si no es YYYY-MM-DD.
     */
    private function parseDate(string $value): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, Delivery::LOCAL_TIMEZONE);

        return $date !== null && $date->format('Y-m-d') === $value ? $date : null;
    }

    private function problemWith(Delivery $delivery): ?string
    {
        if ($delivery->signatureIntact()) {
            return null;
        }

        return Delivery::signatureDisk()->exists($delivery->signature_path) ? 'altered' : 'missing';
    }

    /**
     * Claves "delivery_id:reason" ya registradas en las últimas 24 horas.
     *
     * @return Collection<int, string>
     */
    private function recentlyReported(): Collection
    {
        return SecurityEvent::query()
            ->where('event', SecurityEventType::SignatureIntegrityFailed)
            ->where('created_at', '>=', Date::now()->subHours(self::DUPLICATE_WINDOW_HOURS))
            ->pluck('metadata')
            ->map(fn (?array $metadata) => ($metadata['delivery_id'] ?? '').':'.($metadata['reason'] ?? ''));
    }
}
