<?php

namespace App\Actions\Entregas;

use ErrorException;
use GdImage;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\PngEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Firma de la entrega (RN-024, D-002). Recibe el data URL que envía el
 * cliente y devuelve el PNG re-codificado listo para guardar:
 *
 * 1. Prefijo exacto `data:image/png;base64,` y base64 estricto.
 * 2. ≤ 300 KB decodificada (MAX_BYTES).
 * 3. Firma binaria PNG y dimensiones ≤ 2000 px por lado, leídas del
 *    encabezado antes de decodificar (sin reservar memoria para una imagen
 *    enorme).
 * 4. B-3: al menos 100 × 50 px (MIN_WIDTH × MIN_HEIGHT).
 * 5. Re-codificación con Intervention Image v4 (GD) a PNG sin metadatos:
 *    descarta cualquier contenido que no sea la imagen (p. ej. bytes
 *    agregados al final o fragmentos de texto).
 * 6. B-3: legibilidad. La imagen decodificada se reduce a INK_SAMPLE_WIDTH
 *    px de ancho y al menos MIN_INK_RATIO de sus píxeles deben ser trazo
 *    (no transparentes y no blancos): rechaza lienzos vacíos, blancos o con
 *    un punto suelto.
 *
 * Todo error es ValidationException con la clave "signature".
 */
class ProcessDeliverySignature
{
    public const string PREFIX = 'data:image/png;base64,';

    public const int MAX_BYTES = 300 * 1024;

    public const int MAX_SIDE = 2000;

    /**
     * Longitud máxima del data URL (base64 de MAX_BYTES más el prefijo) para
     * rechazar cuerpos desmedidos antes de decodificar:
     * 22 + 4 * ceil(307200 / 3).
     */
    public const int MAX_DATA_URL_LENGTH = 409_622;

    public const string INVALID_MESSAGE = 'La firma debe ser una imagen PNG válida.';

    public const string TOO_LARGE_MESSAGE = 'La firma es demasiado grande (máximo 300 KB). Límpiala y firma de nuevo.';

    public const string DIMENSIONS_MESSAGE = 'La firma no puede superar 2000 px por lado.';

    public const int MIN_WIDTH = 100;

    public const int MIN_HEIGHT = 50;

    /**
     * Ancho al que se reduce la firma para contar los píxeles de trazo.
     */
    public const int INK_SAMPLE_WIDTH = 200;

    /**
     * Fracción mínima de píxeles de trazo (0,5 %).
     */
    public const float MIN_INK_RATIO = 0.005;

    /**
     * Un canal ≥ este valor en los tres colores se considera blanco (fondo).
     */
    private const int WHITE_THRESHOLD = 240;

    public const string EMPTY_MESSAGE = 'La firma está vacía o no es legible.';

    private const string PNG_MAGIC = "\x89PNG\r\n\x1A\n";

    /**
     * @throws ValidationException
     */
    public function handle(string $dataUrl): string
    {
        if (! str_starts_with($dataUrl, self::PREFIX)) {
            throw self::fail(self::INVALID_MESSAGE);
        }

        $binary = base64_decode(substr($dataUrl, strlen(self::PREFIX)), true);

        if ($binary === false || $binary === '') {
            throw self::fail(self::INVALID_MESSAGE);
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw self::fail(self::TOO_LARGE_MESSAGE);
        }

        $info = str_starts_with($binary, self::PNG_MAGIC) ? @getimagesizefromstring($binary) : false;

        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1) {
            throw self::fail(self::INVALID_MESSAGE);
        }

        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            throw self::fail(self::DIMENSIONS_MESSAGE);
        }

        if ($info[0] < self::MIN_WIDTH || $info[1] < self::MIN_HEIGHT) {
            throw self::fail(self::EMPTY_MESSAGE);
        }

        return $this->reencode($binary);
    }

    /**
     * Como en StoreAssetPhoto (B-3 de 005): las advertencias de GD al
     * decodificar (PNG truncado o corrupto) se convierten en error.
     *
     * @throws ValidationException
     */
    private function reencode(string $binary): string
    {
        $previousHandler = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previousHandler): bool {
            if (preg_match('/^image(create|png)|gd-png|libpng/i', $message) === 1) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return $previousHandler !== null && (bool) $previousHandler($level, $message, $file, $line);
        });

        try {
            $manager = ImageManager::usingDriver(GdDriver::class, decodeAnimation: false, strip: true);

            $image = $manager->decodeBinary($binary);
            $legible = self::hasEnoughInk($image->core()->native());
            $png = $image->encode(new PngEncoder(strip: true))->toString();
        } catch (ImageException|ErrorException) {
            throw self::fail(self::INVALID_MESSAGE);
        } catch (Throwable $exception) {
            report($exception);

            throw self::fail(self::INVALID_MESSAGE);
        } finally {
            restore_error_handler();
        }

        if (! $legible) {
            throw self::fail(self::EMPTY_MESSAGE);
        }

        return $png;
    }

    /**
     * B-3: proporción de píxeles de trazo en una copia reducida a
     * INK_SAMPLE_WIDTH px de ancho (manteniendo la proporción).
     */
    private static function hasEnoughInk(GdImage $native): bool
    {
        $sample = imagescale($native, min(self::INK_SAMPLE_WIDTH, imagesx($native)));

        if ($sample === false) {
            return false;
        }

        if (! imageistruecolor($sample)) {
            imagepalettetotruecolor($sample);
        }

        $width = imagesx($sample);
        $height = imagesy($sample);
        $ink = 0;

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($sample, $x, $y);
                // GD: alfa 0 = opaco, 127 = transparente.
                $transparent = (($color >> 24) & 0x7F) === 127;
                $white = (($color >> 16) & 0xFF) >= self::WHITE_THRESHOLD
                    && (($color >> 8) & 0xFF) >= self::WHITE_THRESHOLD
                    && ($color & 0xFF) >= self::WHITE_THRESHOLD;

                if (! $transparent && ! $white) {
                    $ink++;
                }
            }
        }

        return $ink >= ($width * $height) * self::MIN_INK_RATIO;
    }

    private static function fail(string $message): ValidationException
    {
        return ValidationException::withMessages(['signature' => [$message]]);
    }
}
