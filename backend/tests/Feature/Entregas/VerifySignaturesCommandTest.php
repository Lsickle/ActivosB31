<?php

use App\Enums\SecurityEventType;
use App\Models\Delivery;
use App\Models\SecurityEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * M-3 (revisión de seguridad de 007/008): auditoría periódica de las firmas
 * de entrega (RN-024 / D-002) con Delivery::signatureIntact().
 */
beforeEach(function () {
    Storage::fake(config('filesystems.signatures_disk'));
});

/**
 * Entrega cuyo PNG de firma existe en el disco y coincide con su huella.
 */
function deliveryWithSignature(array $attributes = []): Delivery
{
    // M-1 (revisión de RN-041): la firma íntegra debe ser un PNG real.
    $contents = signaturePng();
    $delivery = Delivery::factory()->create([
        'signature_sha256' => hash('sha256', $contents),
        ...$attributes,
    ]);
    Delivery::signatureDisk()->put($delivery->signature_path, $contents);

    return $delivery;
}

function signatureIntegrityEvents()
{
    return SecurityEvent::query()->where('event', SecurityEventType::SignatureIntegrityFailed)->orderBy('id')->get();
}

it('M-3: con todas las firmas intactas termina en éxito sin registrar eventos', function () {
    deliveryWithSignature();
    deliveryWithSignature();

    $this->artisan('deliveries:verify-signatures')
        ->expectsOutputToContain('2 firmas verificadas, 0 con problemas')
        ->assertSuccessful();

    expect(signatureIntegrityEvents())->toBeEmpty();
});

it('M-3: detecta una firma alterada, la lista y registra el evento sin actor ni petición', function () {
    deliveryWithSignature();
    $altered = deliveryWithSignature();
    Delivery::signatureDisk()->put($altered->signature_path, 'otro contenido');

    $this->artisan('deliveries:verify-signatures')
        ->expectsOutputToContain('2 firmas verificadas, 1 con problemas')
        ->expectsOutputToContain("{$altered->code}: alterada")
        ->assertFailed();

    expect(signatureIntegrityEvents())->toHaveCount(1);
    expect(signatureIntegrityEvents()->sole())
        ->actor_id->toBeNull()
        ->ip_address->toBeNull()
        ->user_agent->toBeNull()
        ->metadata->toEqual(['delivery_id' => $altered->id, 'code' => $altered->code, 'reason' => 'altered']);
});

it('M-3: detecta una firma faltante', function () {
    $missing = deliveryWithSignature();
    Delivery::signatureDisk()->delete($missing->signature_path);

    $this->artisan('deliveries:verify-signatures')
        ->expectsOutputToContain('1 firmas verificadas, 1 con problemas')
        ->expectsOutputToContain("{$missing->code}: faltante")
        ->assertFailed();

    expect(signatureIntegrityEvents()->sole()->metadata)
        ->toEqual(['delivery_id' => $missing->id, 'code' => $missing->code, 'reason' => 'missing']);
});

it('M-3: recorre todas las entregas en lotes', function () {
    foreach (range(1, 5) as $ignored) {
        deliveryWithSignature();
    }
    $missing = Delivery::factory()->create();

    $this->artisan('deliveries:verify-signatures', ['--chunk' => 2])
        ->expectsOutputToContain('6 firmas verificadas, 1 con problemas')
        ->expectsOutputToContain("{$missing->code}: faltante")
        ->assertFailed();
});

it('M-3: --since verifica solo las entregas desde esa fecha (día de America/Bogota)', function () {
    $old = Delivery::factory()->create(['delivered_at' => '2026-10-10 04:59:00']); // 9 oct, 23:59 en Bogotá
    $recent = deliveryWithSignature(['delivered_at' => '2026-10-10 05:00:00']); // 10 oct, 00:00 en Bogotá

    $this->artisan('deliveries:verify-signatures', ['--since' => '2026-10-10'])
        ->expectsOutputToContain('1 firmas verificadas, 0 con problemas')
        ->doesntExpectOutputToContain($old->code)
        ->assertSuccessful();

    expect(signatureIntegrityEvents())->toBeEmpty()
        ->and($recent->signatureIntact())->toBeTrue();
});

it('M-3: rechaza un --since que no sea YYYY-MM-DD', function (string $since) {
    deliveryWithSignature();

    $this->artisan('deliveries:verify-signatures', ['--since' => $since])
        ->expectsOutputToContain('YYYY-MM-DD')
        ->assertFailed();
})->with(['10/10/2026', '2026-13-01', 'ayer']);

it('M-3: no duplica el evento de la misma entrega y razón dentro de 24 horas', function () {
    $delivery = deliveryWithSignature();
    Delivery::signatureDisk()->put($delivery->signature_path, 'otro contenido');

    $this->artisan('deliveries:verify-signatures')->assertFailed();
    $this->travel(23)->hours();
    $this->artisan('deliveries:verify-signatures')
        ->expectsOutputToContain("{$delivery->code}: alterada")
        ->assertFailed();

    expect(signatureIntegrityEvents())->toHaveCount(1);

    // Otra razón para la misma entrega sí se registra.
    Delivery::signatureDisk()->delete($delivery->signature_path);
    $this->artisan('deliveries:verify-signatures')->assertFailed();

    expect(signatureIntegrityEvents()->pluck('metadata.reason')->all())->toBe(['altered', 'missing']);

    // Pasadas las 24 horas se vuelve a registrar.
    $this->travel(25)->hours();
    $this->artisan('deliveries:verify-signatures')->assertFailed();

    expect(signatureIntegrityEvents()->pluck('metadata.reason')->all())->toBe(['altered', 'missing', 'missing']);
});

it('M-3: el evento tiene código y etiqueta propios', function () {
    expect(SecurityEventType::SignatureIntegrityFailed->value)->toBe('signature_integrity_failed')
        ->and(SecurityEventType::SignatureIntegrityFailed->label())->toBe('Firma de entrega alterada o faltante');
});

it('M-3: se programa los domingos a las 03:00 de America/Bogota', function () {
    Artisan::all(); // carga routes/console.php

    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'deliveries:verify-signatures'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 3 * * 0')
        ->and($events->first()->timezone)->toBe('America/Bogota');
});

it('M-1 (revisión de RN-041): un archivo que no es PNG cuenta como alterado aunque su huella coincida', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"></svg>';
    $delivery = Delivery::factory()->create(['signature_sha256' => hash('sha256', $svg)]);
    Delivery::signatureDisk()->put($delivery->signature_path, $svg);

    $this->artisan('deliveries:verify-signatures')
        ->expectsOutputToContain("{$delivery->code}: alterada")
        ->assertFailed();

    expect(signatureIntegrityEvents()->sole()->metadata['reason'])->toBe('altered');
});
