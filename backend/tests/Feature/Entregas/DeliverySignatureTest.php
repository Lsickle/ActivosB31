<?php

use App\Actions\Entregas\ProcessDeliverySignature;
use App\Enums\Permission;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * T003 — Firma de la entrega (RN-024, D-002): data URL image/png, ≤ 300 KB
 * decodificada, ≤ 2000 px por lado, firma binaria PNG verificada y
 * re-codificada con Intervention para descartar contenido que no sea imagen.
 */
function signatureError(string $dataUrl): ?string
{
    try {
        app(ProcessDeliverySignature::class)->handle($dataUrl);
    } catch (ValidationException $exception) {
        return $exception->errors()['signature'][0] ?? null;
    }

    return null;
}

it('D-002: re-codifica una firma PNG válida a PNG', function () {
    $binary = app(ProcessDeliverySignature::class)->handle(signatureDataUrl());

    $info = getimagesizefromstring($binary);

    expect(str_starts_with($binary, "\x89PNG\r\n\x1A\n"))->toBeTrue()
        ->and($info[0])->toBe(400)
        ->and($info[1])->toBe(150)
        ->and($info[2])->toBe(IMAGETYPE_PNG);
});

it('D-002: descarta lo que viene después de la imagen (contenido que no es imagen)', function () {
    $binary = app(ProcessDeliverySignature::class)->handle(signatureDataUrl(signaturePng().'<?php echo "x"; ?>'));

    expect($binary)->not->toContain('<?php');
});

it('D-002: solo acepta data URL image/png en base64 válido', function (string $dataUrl) {
    expect(signatureError($dataUrl))->toBe(ProcessDeliverySignature::INVALID_MESSAGE);
})->with([
    'otro tipo MIME' => fn () => signatureDataUrl(mime: 'image/jpeg'),
    'sin prefijo' => fn () => base64_encode(signaturePng()),
    'base64 inválido' => fn () => 'data:image/png;base64,@@@@',
    'vacía' => fn () => 'data:image/png;base64,',
    'no es PNG' => fn () => signatureDataUrl('GIF89a no es png'),
    'PNG truncado' => fn () => signatureDataUrl(substr(signaturePng(), 0, 60)),
    'SVG disfrazado' => fn () => signatureDataUrl('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
]);

it('D-002: rechaza firmas de más de 300 KB decodificadas', function () {
    $tooBig = signaturePng().str_repeat("\0", 300 * 1024);

    expect(signatureError(signatureDataUrl($tooBig)))->toBe(ProcessDeliverySignature::TOO_LARGE_MESSAGE);
});

it('D-002: rechaza dimensiones de más de 2000 px por lado', function (int $width, int $height) {
    expect(signatureError(signatureDataUrl(signaturePngHeaderOnly($width, $height))))
        ->toBe(ProcessDeliverySignature::DIMENSIONS_MESSAGE);
})->with([[2001, 100], [100, 2001]]);

/**
 * PNG con fondo uniforme y, opcionalmente, un trazo grueso.
 */
function plainSignaturePng(int $width, int $height, bool $transparent, bool $withStroke = false): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, $transparent
        ? imagecolorallocatealpha($image, 0, 0, 0, 127)
        : imagecolorallocate($image, 255, 255, 255));

    if ($withStroke) {
        imagesetthickness($image, 4);
        imageline($image, 10, (int) ($height / 2), $width - 10, (int) ($height / 3), imagecolorallocatealpha($image, 15, 23, 42, 0));
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

it('B-3: rechaza firmas de menos de 100 × 50 px', function (int $width, int $height) {
    expect(signatureError(signatureDataUrl(signaturePng($width, $height))))->toBe(ProcessDeliverySignature::EMPTY_MESSAGE);
})->with([[99, 150], [400, 49]]);

it('B-3: rechaza un PNG transparente de 1 × 1', function () {
    expect(signatureError(signatureDataUrl(plainSignaturePng(1, 1, transparent: true))))
        ->toBe(ProcessDeliverySignature::EMPTY_MESSAGE)
        ->toBe('La firma está vacía o no es legible.');
});

it('B-3: rechaza un lienzo sin trazos (transparente o blanco)', function (bool $transparent) {
    expect(signatureError(signatureDataUrl(plainSignaturePng(400, 150, $transparent))))
        ->toBe(ProcessDeliverySignature::EMPTY_MESSAGE);
})->with(['transparente' => true, 'blanco' => false]);

it('B-3: rechaza un lienzo casi vacío (un punto)', function () {
    $image = imagecreatetruecolor(400, 150);
    imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
    imagesetpixel($image, 200, 75, imagecolorallocate($image, 0, 0, 0));
    ob_start();
    imagepng($image);

    expect(signatureError(signatureDataUrl((string) ob_get_clean())))->toBe(ProcessDeliverySignature::EMPTY_MESSAGE);
});

it('B-3: acepta un trazo sobre fondo transparente (como signature_pad)', function () {
    expect(signatureError(signatureDataUrl(plainSignaturePng(800, 400, transparent: true, withStroke: true))))->toBeNull();
});

it('B-3: POST /api/deliveries responde 422 en signature con una firma vacía', function () {
    Storage::fake('local');
    $scenario = deliveryScenario();
    actingWithPermissions([Permission::EntregasGestionar->value]);

    $this->postJson('/api/deliveries', deliveryPayload($scenario, ['signature' => signatureDataUrl(plainSignaturePng(400, 150, transparent: false))]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['signature' => 'La firma está vacía o no es legible.']);
});
