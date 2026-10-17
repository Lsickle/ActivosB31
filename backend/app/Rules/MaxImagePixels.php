<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * M-1 (revisión de seguridad de 005): limita el total de píxeles de una
 * imagen antes de decodificarla. GD reserva unos 4 bytes por píxel al
 * decodificar: un archivo pequeño con dimensiones enormes ("bomba de
 * descompresión") agotaría la memoria. Las dimensiones se leen de la
 * cabecera con getimagesize(), sin decodificar la imagen.
 */
class MaxImagePixels implements ValidationRule
{
    public function __construct(private int $maxPixels) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile) {
            return;
        }

        $size = @getimagesize($value->getRealPath());

        if ($size === false) {
            // Archivo ilegible: lo rechazan las reglas image/mimes.
            return;
        }

        if ($this->maxPixels < $size[0] * $size[1]) {
            $fail('La imagen no puede superar :max megapíxeles.')->translate([
                'max' => (string) intdiv($this->maxPixels, 1_000_000),
            ]);
        }
    }
}
