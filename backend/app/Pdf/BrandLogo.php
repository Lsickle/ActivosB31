<?php

namespace App\Pdf;

/**
 * Logo de la estación listo para incrustar en un PDF (RN-041): URI
 * data:image/png;base64 y dimensiones en píxeles.
 */
final readonly class BrandLogo
{
    public function __construct(
        public string $dataUri,
        public int $widthPx,
        public int $heightPx,
    ) {}

    /**
     * Tamaño (en mm, redondeado a 0,1) que cabe en la caja dada conservando
     * la proporción.
     *
     * @return array{width: float, height: float}
     */
    public function fit(float $maxWidthMm, float $maxHeightMm): array
    {
        $scale = min($maxWidthMm / $this->widthPx, $maxHeightMm / $this->heightPx);

        return [
            'width' => round($this->widthPx * $scale, 1),
            'height' => round($this->heightPx * $scale, 1),
        ];
    }
}
