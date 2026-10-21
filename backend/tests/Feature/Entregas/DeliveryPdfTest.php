<?php

use App\Actions\Entregas\CreateDelivery;
use App\Actions\Entregas\RegisterDeliveryReturn;
use App\Enums\DeliveryKind;
use App\Enums\Permission;
use App\Enums\SecurityEventType;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Location;
use App\Models\Position;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';
require_once __DIR__.'/../../Support/pdf.php';

/**
 * RN-041 — Acta PDF de una entrega: GET /api/deliveries/{id}/pdf.
 *
 * - La descarga quien hoy ve la firma en la ficha: entregas.ver y además
 *   DeliveryPolicy::viewSignature (entregas.gestionar o empleados.ver; M-1).
 * - Trae artículos, quién entrega (usuario y cargo de su empleado), quién
 *   recibe (nombre, cargo y firma), devoluciones y estado actual; nunca el
 *   documento del empleado (RN-022, Ley 1581).
 * - M-3: si la firma fue alterada o falta, 409 sin PDF y evento
 *   signature_integrity_failed en la bitácora.
 * - Cada descarga deja delivery_pdf_exported en la bitácora.
 */
beforeEach(function () {
    Storage::fake('local');
    // 2026-10-20 15:30 UTC = 10:30 en Bogotá.
    Carbon::setTestNow(Carbon::parse('2026-10-20 15:30:00', 'UTC'));
    useBrandLogo();

    $this->scenario = deliveryScenario();
    $this->scenario['employee']->forceFill([
        'document_number' => '80777666',
        'position_id' => Position::factory()->create(['name' => 'Bombero de línea'])->id,
    ])->save();

    $this->deliverer = User::factory()->create([
        'name' => 'Carlos Almacén',
        'employee_id' => Employee::factory()->create([
            'position_id' => Position::factory()->create(['name' => 'Sargento de almacén'])->id,
        ])->id,
    ]);

    $this->delivery = app(CreateDelivery::class)->handle(
        $this->scenario['employee'], DeliveryKind::Prestamo, '2026-10-30', $this->scenario['warehouse'],
        '<script>alert("x")</script> casco nuevo', signaturePng(),
        [['asset_id' => $this->scenario['helmet']->id], ['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 3]],
        $this->deliverer,
    );

    $this->engine = Location::factory()->create(['name' => 'Máquina 1']);
    Carbon::setTestNow(Carbon::parse('2026-10-21 14:00:00', 'UTC'));
    app(RegisterDeliveryReturn::class)->handle($this->delivery, [[
        'delivery_item_id' => $this->delivery->items()->whereNull('asset_id')->value('id'),
        'quantity' => 1, 'condition' => 'good', 'to_location_id' => $this->engine->id, 'notes' => null,
    ]], $this->deliverer);
});

afterEach(fn () => Carbon::setTestNow());

function deliveryPdfUrl(Delivery $delivery): string
{
    return "/api/deliveries/{$delivery->id}/pdf";
}

function deliveryPdfEvents(SecurityEventType $type)
{
    return SecurityEvent::query()->where('event', $type)->orderBy('id')->get();
}

describe('permisos (RN-041, M-1)', function () {
    it('RN-041: el Administrador y el Almacenista descargan el acta en PDF', function (string $role) {
        actingAsRole($role);

        $response = $this->get(deliveryPdfUrl($this->delivery))->assertOk();

        expect($response->getContent())->toStartWith('%PDF-');
    })->with(['admin', 'almacenista']);

    it('RN-041: el rol Consulta (sin firma visible) recibe 403', function () {
        actingAsRole('consulta');

        $this->getJson(deliveryPdfUrl($this->delivery))->assertForbidden();
        expect(deliveryPdfEvents(SecurityEventType::DeliveryPdfExported))->toBeEmpty();
    });

    it('RN-041: exige entregas.ver y además entregas.gestionar o empleados.ver', function (array $permissions, int $status) {
        actingWithPermissions(array_map(fn (Permission $p) => $p->value, $permissions));

        $this->getJson(deliveryPdfUrl($this->delivery))->assertStatus($status);
    })->with([
        'entregas.ver + entregas.gestionar' => [[Permission::EntregasVer, Permission::EntregasGestionar], 200],
        'entregas.ver + empleados.ver' => [[Permission::EntregasVer, Permission::EmpleadosVer], 200],
        'solo entregas.ver' => [[Permission::EntregasVer], 403],
        'solo empleados.ver' => [[Permission::EmpleadosVer], 403],
        'solo entregas.gestionar' => [[Permission::EntregasGestionar], 403],
    ]);

    it('RN-041: sin sesión responde 401', function () {
        $this->getJson(deliveryPdfUrl($this->delivery))->assertUnauthorized();
    });

    it('RN-041: una entrega inexistente responde 404', function () {
        actingAsRole('admin');

        $this->getJson('/api/deliveries/999999/pdf')->assertNotFound();
    });
});

describe('descarga (RN-041)', function () {
    it('RN-041: responde como descarga con el consecutivo como nombre y sin caché', function () {
        actingAsRole('admin');

        $response = $this->get(deliveryPdfUrl($this->delivery))->assertOk();

        expect($response->headers->get('Content-Type'))->toBe('application/pdf')
            ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.$this->delivery->code.'.pdf"')
            ->and($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
            ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
            ->and($response->headers->get('Content-Length'))->toBe((string) strlen($response->getContent()));
    });

    it('RN-041: registra delivery_pdf_exported en la bitácora con quién y qué entrega', function () {
        $user = actingAsRole('almacenista');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();

        $event = deliveryPdfEvents(SecurityEventType::DeliveryPdfExported)->sole();
        expect($event->actor_id)->toBe($user->id)
            ->and($event->metadata)->toEqual(['delivery_id' => $this->delivery->id, 'code' => $this->delivery->code])
            ->and($event->ip_address)->not->toBeNull();
    });

    it('RN-041: comparte el límite de descargas (10 por minuto por usuario; la 11.ª da 429)', function () {
        actingAsRole('admin');

        for ($i = 0; $i < 10; $i++) {
            $this->getJson(deliveryPdfUrl($this->delivery))->assertOk();
        }

        $this->getJson(deliveryPdfUrl($this->delivery))->assertTooManyRequests();
    });
});

describe('contenido (RN-041, RN-022)', function () {
    it('RN-041: lleva entrega, quién entrega y recibe con cargo y firma, artículos, devoluciones y estado', function () {
        $user = actingAsRole('admin');
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();
        $pdfHtml = $html();

        $signature = Delivery::signatureDisk()->get($this->delivery->signature_path);

        expect($pdfHtml)->toContain('Acta de entrega '.$this->delivery->code)
            ->toContain('2026-10-20 10:30')          // entrega en hora de Bogotá
            ->toContain('Préstamo')
            ->toContain('2026-10-30')                // fecha límite
            ->toContain('Bodega')                    // ubicación de origen
            ->toContain('Carlos Almacén')
            ->toContain('Sargento de almacén')
            ->toContain('Ana Rojas')
            ->toContain('Bombero de línea')
            ->toContain('class="signature" src="data:image/png;base64,'.base64_encode($signature).'"')
            ->toContain('CAS-001')
            ->toContain('Guantes')
            ->toContain('2026-10-21 09:00')          // devolución en hora de Bogotá
            ->toContain('Máquina 1')
            ->toContain('Bueno')
            ->toContain('Abierta')                   // estado actual
            ->toContain($this->delivery->signature_sha256)
            ->toContain('Generado el 2026-10-21 09:00 por '.e($user->name));

        // Solo la firma y el logo (arriba a la derecha y marca de agua).
        expect(count(pdfImgTags($pdfHtml)))->toBe(3);
    });

    it('M-2 (revisión de RN-041): el pie aclara que es una copia y que lo vigente es el sistema', function () {
        actingAsRole('admin');
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();

        expect($html())->toContain("Copia generada del registro {$this->delivery->code} el 2026-10-21 09:00. La información vigente es la del sistema ActivosB31.");
    });

    it('RN-022: no incluye el documento ni otros datos personales del empleado', function () {
        actingAsRole('admin');
        $this->scenario['employee']->forceFill(['phone' => '3009998877', 'email' => 'ana@example.com'])->save();
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();
        $pdfHtml = $html();

        foreach (['80777666', '3009998877', 'ana@example.com', 'Documento', 'Teléfono', 'Correo'] as $secret) {
            expect($pdfHtml)->not->toContain($secret);
        }
    });

    it('M-1 (revisión de RN-041): una imagen data:image/svg+xml en las observaciones no se renderiza', function () {
        actingAsRole('admin');
        $notes = '<img src="data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg"/>').'">';
        $delivery = app(CreateDelivery::class)->handle(
            $this->scenario['employee'], DeliveryKind::Dotacion, null, $this->scenario['warehouse'], $notes, signaturePng(),
            [['asset_type_id' => $this->scenario['gloves']->id, 'quantity' => 1]], $this->deliverer,
        );
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($delivery))->assertOk();
        $pdfHtml = $html();

        // Logo, marca de agua y firma: nada más.
        expect(count(pdfImgTags($pdfHtml)))->toBe(3)
            ->and($pdfHtml)->toContain('&lt;img src=&quot;data:image/svg+xml;base64,');
    });
    it('RN-041: escapa las observaciones (nunca HTML del usuario)', function () {
        actingAsRole('admin');
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();
        $pdfHtml = $html();

        expect($pdfHtml)->toContain('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; casco nuevo')
            ->not->toContain('<script');
    });

    it('RN-041: si quien entregó no tiene empleado vinculado, se muestra solo su nombre', function () {
        actingAsRole('admin');
        $this->deliverer->forceFill(['employee_id' => null])->save();
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();

        expect($html())->toContain('Carlos Almacén')->not->toContain('Sargento de almacén');
    });

    it('RN-041: sin el archivo del logo el acta se genera igual (solo la firma)', function () {
        actingAsRole('admin');
        withoutBrandLogo();
        $html = capturePdfHtml('deliveries.pdf');

        $this->get(deliveryPdfUrl($this->delivery))->assertOk();

        expect(count(pdfImgTags($html())))->toBe(1);
    });
});

describe('integridad de la firma (M-3)', function () {
    it('M-3 / RN-041: con la firma alterada responde 409, no genera el PDF y lo registra en la bitácora', function () {
        $user = actingAsRole('admin');
        Delivery::signatureDisk()->put($this->delivery->signature_path, 'otro contenido');

        $this->getJson(deliveryPdfUrl($this->delivery))
            ->assertConflict()
            ->assertExactJson(['message' => 'La firma de esta entrega no pasó la verificación de integridad; no se puede generar el acta.']);

        $event = deliveryPdfEvents(SecurityEventType::SignatureIntegrityFailed)->sole();
        expect($event->actor_id)->toBe($user->id)
            ->and($event->metadata)->toEqual(['delivery_id' => $this->delivery->id, 'code' => $this->delivery->code, 'reason' => 'altered'])
            ->and(deliveryPdfEvents(SecurityEventType::DeliveryPdfExported))->toBeEmpty();
    });

    it('M-3 / RN-041: con la firma faltante responde 409 con razón missing', function () {
        actingAsRole('admin');
        Delivery::signatureDisk()->delete($this->delivery->signature_path);

        $this->getJson(deliveryPdfUrl($this->delivery))->assertConflict();

        expect(deliveryPdfEvents(SecurityEventType::SignatureIntegrityFailed)->sole()->metadata['reason'])->toBe('missing')
            ->and(deliveryPdfEvents(SecurityEventType::DeliveryPdfExported))->toBeEmpty();
    });
    it('M-1 (revisión de RN-041): un archivo con la huella correcta que no es PNG (SVG) da 409 alterada', function () {
        actingAsRole('admin');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image href="file:///etc/passwd"/></svg>';
        $delivery = Delivery::factory()->create(['signature_sha256' => hash('sha256', $svg)]);
        Delivery::signatureDisk()->put($delivery->signature_path, $svg);

        $this->getJson(deliveryPdfUrl($delivery))->assertConflict();

        expect(deliveryPdfEvents(SecurityEventType::SignatureIntegrityFailed)->sole()->metadata['reason'])->toBe('altered')
            ->and(deliveryPdfEvents(SecurityEventType::DeliveryPdfExported))->toBeEmpty();
    });

    it('M-1 (revisión de RN-041): un PNG con la huella correcta pero de más de 2000 px da 409 alterada', function () {
        actingAsRole('admin');
        $png = signaturePngHeaderOnly(2001, 100);
        $delivery = Delivery::factory()->create(['signature_sha256' => hash('sha256', $png)]);
        Delivery::signatureDisk()->put($delivery->signature_path, $png);

        $this->getJson(deliveryPdfUrl($delivery))->assertConflict();
    });
});
