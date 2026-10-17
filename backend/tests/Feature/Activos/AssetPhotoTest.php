<?php

use App\Actions\Activos\StoreAssetPhoto;
use App\Enums\AssetEventType;
use App\Http\Requests\Activos\AssetPhotoRules;
use App\Models\Asset;
use App\Models\AssetType;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;

/**
 * 005 — Foto del activo (FR-008, pregunta 25, edge case de la spec): disco
 * privado, re-codificada a JPEG ≤ 1600 px sin metadatos, URL temporal.
 */

/**
 * JPEG de prueba con un segmento EXIF (APP1) cuyo campo Make contiene
 * "GPSSECRET", para comprobar que la foto guardada no lo conserva.
 */
function jpegWithExif(int $width, int $height): UploadedFile
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    // TIFF little endian: una entrada IFD (0x010F Make, ASCII, 10 bytes en el offset 26).
    $tiff = "II*\0".pack('V', 8).pack('v', 1).pack('vvVV', 0x010F, 2, 10, 26).pack('V', 0)."GPSSECRET\0";
    $app1 = "Exif\0\0".$tiff;
    $jpeg = substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);

    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $jpeg);

    return new UploadedFile($path, 'foto.jpg', 'image/jpeg', null, true);
}

/**
 * PNG con solo la firma y el encabezado IHDR: getimagesize() lee sus
 * dimensiones sin que haga falta decodificar (ni reservar memoria para) la
 * imagen completa.
 */
function pngHeaderOnly(int $width, int $height): UploadedFile
{
    $ihdr = 'IHDR'.pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);
    $png = "\x89PNG\r\n\x1A\n".pack('N', 13).$ihdr.pack('N', crc32($ihdr));

    return UploadedFile::fake()->createWithContent('enorme.png', $png);
}

/**
 * JPEG válido cortado por la mitad: la cabecera (y getimagesize) siguen
 * siendo correctos, pero faltan datos de la imagen.
 */
function truncatedJpeg(): UploadedFile
{
    $image = imagecreatetruecolor(400, 300);
    for ($x = 0; $x < 400; $x++) {
        imageline($image, $x, 0, 399 - $x, 299, imagecolorallocate($image, $x % 256, ($x * 3) % 256, 100));
    }
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    return UploadedFile::fake()->createWithContent('cortada.jpg', substr($jpeg, 0, intdiv(strlen($jpeg), 2)));
}

function storedPhoto(Asset $asset): string
{
    return Storage::disk('local')->get($asset->fresh()->photo_path);
}

beforeEach(function () {
    Storage::fake('local');
});

describe('re-codificación (pregunta 25)', function () {
    it('FR-008: al registrar con foto la guarda privada, ≤ 1600 px, JPEG y sin EXIF', function () {
        actingAsRole('almacenista');
        $photo = jpegWithExif(3000, 2000);
        expect(file_get_contents($photo->getRealPath()))->toContain('GPSSECRET');

        $response = $this->post('/api/assets', [
            'asset_type_id' => AssetType::factory()->create(['tracking_mode' => 'serialized'])->id,
            'plate' => 'B31-0001',
            'photo' => $photo,
        ], ['Accept' => 'application/json'])->assertCreated();

        $asset = Asset::query()->findOrFail($response->json('data.id'));
        expect($asset->photo_path)->toMatch("#^assets/{$asset->id}/[0-9a-f-]{36}\\.jpg$#");

        $binary = storedPhoto($asset);
        [$width, $height, $type] = getimagesizefromstring($binary);

        expect([$width, $height])->toBe([1600, 1067])
            ->and($type)->toBe(IMAGETYPE_JPEG)
            ->and($binary)->not->toContain('GPSSECRET')
            ->and($binary)->not->toContain("Exif\0\0")
            ->and($response->json('data.photo_url'))->toContain($asset->photo_path)
            ->and($asset->events()->pluck('event')->all())->toBe([AssetEventType::Created]);
    });

    it('convierte PNG y WebP a JPEG sin agrandar imágenes pequeñas', function (string $format) {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image("foto.{$format}", 800, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id);

        [$width, $height, $type] = getimagesizefromstring(storedPhoto($asset));
        expect([$width, $height, $type])->toBe([800, 600, IMAGETYPE_JPEG]);
    })->with(['png', 'webp']);
});

it('M-2: la extensión GD del entorno decodifica WebP', function () {
    expect(gd_info()['WebP Support'] ?? false)->toBeTrue();
});

describe('reemplazo y eliminación', function () {
    it('edge case: reemplazar la foto borra el archivo anterior y queda en el historial', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $first = $asset->fresh()->photo_path;

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $second = $asset->fresh()->photo_path;

        expect($second)->not->toBe($first);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);
        expect($asset->events()->orderBy('id')->pluck('notes')->all())->toBe(['Foto agregada.', 'Foto reemplazada.']);
    });

    it('la edición multipart (POST) acepta una foto nueva', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->post("/api/assets/{$asset->id}", ['brand' => 'MSA', 'photo' => UploadedFile::fake()->image('a.jpg', 100, 100)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.brand', 'MSA');

        Storage::disk('local')->assertExists($asset->fresh()->photo_path);
    });

    it('B-1: si borrar el archivo anterior falla tras confirmar, se reporta y la operación responde bien', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();
        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 100, 100)], ['Accept' => 'application/json'])->assertOk();

        $fake = Storage::disk('local');
        $failingDelete = Mockery::mock(get_class($fake), [$fake->getDriver(), $fake->getAdapter(), $fake->getConfig()])->makePartial();
        $failingDelete->shouldReceive('delete')->andThrow(new RuntimeException('almacenamiento caído'));
        $failingDelete->shouldReceive('temporaryUrl')->andReturn('https://fotos.test/firmada');
        Storage::set('local', $failingDelete);
        Exceptions::fake();

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('b.jpg', 100, 100)], ['Accept' => 'application/json'])->assertOk();
        $this->deleteJson("/api/assets/{$asset->id}/photo")->assertOk()->assertJsonPath('data.photo_url', null);

        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'almacenamiento caído');
        expect($asset->fresh()->photo_path)->toBeNull()
            ->and($asset->events()->orderBy('id')->pluck('notes')->all())->toBe(['Foto agregada.', 'Foto reemplazada.', 'Foto eliminada.']);
    });

    it('elimina la foto y su archivo', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();
        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 100, 100)], ['Accept' => 'application/json']);
        $path = $asset->fresh()->photo_path;

        $this->deleteJson("/api/assets/{$asset->id}/photo")
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);

        Storage::disk('local')->assertMissing($path);
        expect($asset->fresh()->photo_path)->toBeNull()
            ->and($asset->events()->latest('id')->value('notes'))->toBe('Foto eliminada.');
    });
});

describe('validación y autorización', function () {
    it('edge case / M-1: rechaza archivos que no son JPEG, PNG o WebP, de más de 4 MB, de más de 6000 px o de más de 24 megapíxeles', function (?UploadedFile $file) {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => $file], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('photo');

        expect($asset->fresh()->photo_path)->toBeNull();
    })->with([
        'pdf' => fn () => UploadedFile::fake()->create('acta.pdf', 10, 'application/pdf'),
        'gif' => fn () => UploadedFile::fake()->image('anim.gif', 10, 10),
        'svg' => fn () => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        'más de 4 MB' => fn () => UploadedFile::fake()->image('grande.jpg', 10, 10)->size(4097),
        'más de 6000 px' => fn () => UploadedFile::fake()->image('ancha.jpg', 6001, 10),
        'más de 24 megapíxeles (5000 × 4801)' => fn () => pngHeaderOnly(5000, 4801),
        'sin archivo' => fn () => null,
    ]);

    it('M-1: acepta exactamente 24 megapíxeles (6000 × 4000) en la validación', function () {
        $validator = validator(['photo' => pngHeaderOnly(6000, 4000)], ['photo' => AssetPhotoRules::rules(required: true)]);

        expect($validator->passes())->toBeTrue();
    });

    it('B-3: un JPEG truncado con cabecera válida da 422 en photo, no 500 ni una foto a medias', function () {
        actingAsRole('almacenista');
        $asset = Asset::factory()->create();

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => truncatedJpeg()], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['photo' => StoreAssetPhoto::UNREADABLE_MESSAGE]);

        expect($asset->fresh()->photo_path)->toBeNull()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('una foto inválida al registrar no deja el activo creado', function () {
        actingAsRole('almacenista');

        $this->post('/api/assets', [
            'asset_type_id' => AssetType::factory()->create(['tracking_mode' => 'serialized'])->id,
            'plate' => 'B31-0001',
            'photo' => UploadedFile::fake()->create('acta.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertJsonValidationErrors('photo');

        expect(Asset::query()->count())->toBe(0);
    });

    it('FR-007: subir o quitar la foto exige activos.gestionar', function () {
        $asset = Asset::factory()->create();
        actingWithPermissions(['activos.ver']);

        $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->deleteJson("/api/assets/{$asset->id}/photo")->assertForbidden();
    });

    it('el listado no firma una URL por fila; la ficha sí', function () {
        actingAsRole('consulta');
        $asset = Asset::factory()->create(['photo_path' => 'assets/1/x.jpg']);

        $this->getJson('/api/assets')->assertJsonPath('data.0.photo_url', null);
        expect($this->getJson("/api/assets/{$asset->id}")->json('data.photo_url'))->toContain('assets/1/x.jpg');
    });
});

describe('URL temporal en el disco local real (FR-008, US1-6)', function () {
    it('sirve la foto solo con firma válida y durante 10 minutos', function () {
        $root = sys_get_temp_dir().'/activosb31-photos-'.uniqid();
        config(['filesystems.disks.local.root' => $root]);
        Storage::forgetDisk('local');

        actingAsRole('almacenista');
        $asset = Asset::factory()->create();
        $url = $this->post("/api/assets/{$asset->id}/photo", ['photo' => UploadedFile::fake()->image('a.jpg', 50, 50)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.photo_url');

        expect($url)->toContain('signature=')->toContain('expires=');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->get(preg_replace('/signature=[^&]+/', 'signature=falsa', $url))->assertForbidden();
        $this->get(strtok($url, '?'))->assertForbidden();

        $this->travel(11)->minutes();
        $this->get($url)->assertForbidden();

        (new Filesystem)->deleteDirectory($root);
    });
});
