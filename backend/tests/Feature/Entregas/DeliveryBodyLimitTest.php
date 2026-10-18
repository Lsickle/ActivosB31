<?php

use App\Enums\Permission;
use App\Models\Delivery;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/deliveries.php';

/**
 * Revisión de seguridad de 007: POST /api/deliveries no acepta cuerpos de más
 * de 1 MB (la firma ya tiene su propio tope de 300 KB, RN-024). Se corta antes
 * de validar o decodificar nada.
 */
beforeEach(function () {
    Storage::fake('local');
    $this->scenario = deliveryScenario();
    actingWithPermissions([Permission::EntregasVer->value, Permission::EntregasGestionar->value]);
});

it('rechaza con 413 un cuerpo de más de 1 MB según Content-Length', function () {
    $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['notes' => str_repeat('a', 1_100_000)]))
        ->assertStatus(413)
        ->assertJson(['message' => 'La petición es demasiado grande (máximo 1 MB).']);

    expect(Delivery::query()->count())->toBe(0);
});

it('rechaza con 413 un cuerpo de más de 1 MB sin cabecera Content-Length', function () {
    $content = json_encode(deliveryPayload($this->scenario, ['notes' => str_repeat('a', 1_100_000)]));

    $this->call('POST', '/api/deliveries', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
    ], $content)->assertStatus(413);
});

it('deja pasar un cuerpo de menos de 1 MB a la validación normal', function () {
    $this->postJson('/api/deliveries', deliveryPayload($this->scenario, ['notes' => str_repeat('a', 900_000)]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('notes');
});

it('rechaza con 415 un cuerpo de formulario (solo se acepta JSON)', function () {
    $this->post('/api/deliveries', deliveryPayload($this->scenario), ['Accept' => 'application/json'])
        ->assertStatus(415)
        ->assertJson(['message' => 'El cuerpo de la petición debe ser JSON.']);

    expect(Delivery::query()->count())->toBe(0);
});

it('rechaza con 415 un JSON enviado con otro Content-Type', function (string $contentType) {
    $this->call('POST', '/api/deliveries', [], [], [], [
        'CONTENT_TYPE' => $contentType,
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode(deliveryPayload($this->scenario)))->assertStatus(415);

    expect(Delivery::query()->count())->toBe(0);
})->with([
    'text/plain' => ['text/plain'],
    'multipart' => ['multipart/form-data; boundary=x'],
    'urlencoded' => ['application/x-www-form-urlencoded'],
]);

it('acepta application/json con charset', function () {
    $this->call('POST', '/api/deliveries', [], [], [], [
        'CONTENT_TYPE' => 'application/json; charset=utf-8',
        'HTTP_ACCEPT' => 'application/json',
    ], json_encode(deliveryPayload($this->scenario)))->assertCreated();
});

it('la entrega normal sigue funcionando', function () {
    $this->postJson('/api/deliveries', deliveryPayload($this->scenario))->assertCreated();
});
