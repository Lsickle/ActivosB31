<?php

namespace App\Pdf;

use Illuminate\Support\Facades\Log;

/**
 * Logo de la estación para los PDF (RN-041), leído de
 * config('branding.logo_path'). Se registra como scoped en el contenedor: el
 * archivo se lee una sola vez por petición. Si falta, no es un PNG o supera
 * los topes (B-1 de la revisión de seguridad: ≤ 300 KB y ≤ 2000 px por lado,
 * porque se incrusta dos veces en cada PDF), los PDF se generan sin logo
 * (nunca fallan por esto) y se registra un warning una sola vez por proceso
 * y ruta.
 */
class Branding
{
    public const int MAX_BYTES = 300 * 1024;

    public const int MAX_SIDE = 2000;

    /**
     * Rutas cuyo problema ya se registró en este proceso.
     *
     * @var array<string, true>
     */
    private static array $warned = [];

    private bool $resolved = false;

    private ?BrandLogo $logo = null;

    public function logo(): ?BrandLogo
    {
        if (! $this->resolved) {
            $this->logo = $this->load();
            $this->resolved = true;
        }

        return $this->logo;
    }

    private function load(): ?BrandLogo
    {
        $path = (string) config('branding.logo_path');

        if (! is_file($path) || ! is_readable($path)) {
            return $this->warn('no se encontró el archivo del logo de la estación.', $path);
        }

        $size = filesize($path);

        if ($size === false || $size === 0) {
            return $this->warn('el archivo del logo de la estación está vacío.', $path);
        }

        if ($size > self::MAX_BYTES) {
            return $this->warn('el logo de la estación supera 300 KB.', $path);
        }

        $contents = file_get_contents($path);
        $info = $contents === false ? false : @getimagesizefromstring($contents);

        if ($info === false || $info[2] !== IMAGETYPE_PNG || $info[0] < 1 || $info[1] < 1) {
            return $this->warn('el archivo del logo de la estación no es un PNG válido.', $path);
        }

        if ($info[0] > self::MAX_SIDE || $info[1] > self::MAX_SIDE) {
            return $this->warn('el logo de la estación supera 2000 px por lado.', $path);
        }

        return new BrandLogo('data:image/png;base64,'.base64_encode((string) $contents), $info[0], $info[1]);
    }

    private function warn(string $reason, string $path): null
    {
        if (! isset(self::$warned[$path])) {
            self::$warned[$path] = true;
            Log::warning("PDF sin logo: {$reason}", ['path' => $path]);
        }

        return null;
    }
}
