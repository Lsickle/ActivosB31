<?php

namespace App\Http\Requests\Activos;

use App\Rules\MaxImagePixels;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * FR-008 / edge case de 005: JPEG, PNG o WebP de hasta 4 MB, 6000 px por
 * lado y 24 megapíxeles en total (docs/infraestructura.md). Después se
 * re-codifica (StoreAssetPhoto).
 *
 * M-1 (revisión de seguridad de 005): 4 MB por el límite de cuerpo del proxy
 * de Vercel (~4,5 MB por petición) y 24 Mpx para acotar la memoria de GD al
 * decodificar (memory_limit=256M en producción).
 */
final class AssetPhotoRules
{
    public const int MAX_KILOBYTES = 4096;

    public const int MAX_SIDE_PX = 6000;

    public const int MAX_PIXELS = 24_000_000;

    /**
     * @return list<string|ValidationRule>
     */
    public static function rules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'bail', 'file', 'image', 'mimes:jpeg,png,webp', 'max:'.self::MAX_KILOBYTES,
            'dimensions:max_width='.self::MAX_SIDE_PX.',max_height='.self::MAX_SIDE_PX,
            new MaxImagePixels(self::MAX_PIXELS),
        ];
    }
}
