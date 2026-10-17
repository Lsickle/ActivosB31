<?php

namespace App\Actions\Activos;

use App\Actions\Activos\Concerns\LocksAsset;
use App\Enums\AssetEventType;
use App\Models\Asset;
use App\Models\User;
use ErrorException;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\Exceptions\ImageException;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

/**
 * Guarda la foto de un activo (FR-008, pregunta 25, docs/infraestructura.md):
 * se re-codifica con Intervention Image v4 (GD) a JPEG calidad 82, máximo
 * 1600 px por lado, sin metadatos (EXIF con GPS, Ley 1581) y orientada según
 * su EXIF antes de descartarlo. Se guarda en el disco privado ASSETS_DISK en
 * assets/{asset_id}/{uuid}.jpg. Reemplazar borra el archivo anterior cuando
 * la transacción confirma.
 *
 * B-1 (revisión de seguridad de 005): la foto anterior se lee y la nueva se
 * escribe con la fila del activo bloqueada (dos subidas simultáneas no dejan
 * archivos huérfanos), y un fallo al borrar el archivo anterior tras
 * confirmar se reporta sin convertir la operación en error.
 */
class StoreAssetPhoto
{
    use LocksAsset;

    public const int MAX_SIDE = 1600;

    public const int JPEG_QUALITY = 82;

    public const string UNREADABLE_MESSAGE = 'No se pudo procesar la imagen. Intente con otro archivo JPEG, PNG o WebP.';

    public function __construct(private RecordAssetEvent $recordEvent) {}

    /**
     * @throws ValidationException
     */
    public function handle(Asset $asset, UploadedFile $file, User $user): Asset
    {
        return $this->storeEncoded($asset, $this->reencode($file), $user);
    }

    /**
     * Guarda una foto ya re-codificada con reencode().
     */
    public function storeEncoded(Asset $asset, string $binary, User $user, bool $recordEvent = true): Asset
    {
        $disk = Asset::photoDisk();
        $path = "assets/{$asset->getKey()}/".Str::uuid().'.jpg';

        if (! $disk->put($path, $binary)) {
            throw new RuntimeException('No se pudo guardar la foto del activo.');
        }

        try {
            $previous = $this->withLockedAsset($asset, function (Asset $locked) use ($path, $recordEvent, $user) {
                $previous = $locked->photo_path;
                $locked->forceFill(['photo_path' => $path])->save();

                if ($recordEvent) {
                    $this->recordEvent->handle($locked, AssetEventType::Updated, $user, [
                        'notes' => $previous === null ? 'Foto agregada.' : 'Foto reemplazada.',
                    ]);
                }

                return $previous;
            });
        } catch (Throwable $exception) {
            self::deleteQuietly($disk, $path);

            throw $exception;
        }

        if ($previous !== null) {
            DB::afterCommit(fn () => self::deleteQuietly($disk, $previous));
        }

        return $asset;
    }

    /**
     * B-3: cualquier fallo al decodificar (incluidas las advertencias de
     * libjpeg ante un JPEG truncado, que GD por defecto ignora y que dejarían
     * una foto a medias) se convierte en error de validación de "photo".
     *
     * @throws ValidationException si el archivo no se puede decodificar.
     */
    public function reencode(UploadedFile $file): string
    {
        $previousIgnoreWarning = ini_set('gd.jpeg_ignore_warning', '0');
        // Intervention silencia con @ las funciones de GD, pero el manejador
        // recibe igual sus advertencias: las de decodificación de GD se
        // convierten en excepción. Las demás (p. ej. un EXIF imperfecto, muy
        // común en fotos de celular) siguen al manejador anterior, que respeta
        // el @.
        $previousHandler = set_error_handler(function (int $level, string $message, string $file = '', int $line = 0) use (&$previousHandler): bool {
            if (preg_match('/^image(create|jpeg|png|webp|scale|copy)|gd-(jpeg|png|webp)/i', $message) === 1) {
                throw new ErrorException($message, 0, $level, $file, $line);
            }

            return $previousHandler !== null && (bool) $previousHandler($level, $message, $file, $line);
        });

        try {
            $manager = ImageManager::usingDriver(GdDriver::class, autoOrientation: true, decodeAnimation: false, strip: true);

            return $manager->decodePath($file->getRealPath())
                ->scaleDown(self::MAX_SIDE, self::MAX_SIDE)
                ->encode(new JpegEncoder(quality: self::JPEG_QUALITY, strip: true))
                ->toString();
        } catch (ImageException|ErrorException) {
            throw self::unreadable();
        } catch (Throwable $exception) {
            report($exception);

            throw self::unreadable();
        } finally {
            restore_error_handler();

            if ($previousIgnoreWarning !== false) {
                ini_set('gd.jpeg_ignore_warning', $previousIgnoreWarning);
            }
        }
    }

    /**
     * B-1: un archivo que no se pudo borrar queda huérfano en el disco, pero
     * la foto ya quedó guardada: se reporta para limpiarlo, sin fallar.
     */
    public static function deleteQuietly(Filesystem $disk, string $path): void
    {
        try {
            $disk->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private static function unreadable(): ValidationException
    {
        return ValidationException::withMessages(['photo' => [self::UNREADABLE_MESSAGE]]);
    }
}
