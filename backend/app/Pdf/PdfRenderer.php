<?php

namespace App\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;

/**
 * Único punto donde se generan los PDF de la app (reportes de 008 y acta de
 * entrega, RN-041).
 *
 * La configuración global de dompdf (config/dompdf.php) sigue cerrada: ningún
 * protocolo, sin recursos remotos, sin PHP ni JavaScript (R-7 de 008). Aquí,
 * en tiempo de ejecución y solo para estos documentos, se permite únicamente
 * data:// para incrustar el logo de la estación y la firma de la entrega, que
 * genera la propia app (nunca HTML del usuario: todo dato va escapado). Sigue
 * sin enable_remote y con el mismo chroot, así que file://, rutas locales y
 * http(s):// no se cargan.
 *
 * Las vistas reciben $brandLogo (?BrandLogo) para el layout pdf.layout.
 */
class PdfRenderer
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_PROTOCOLS = ['data://'];

    public function __construct(private readonly Branding $branding) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function make(string $view, array $data, string $orientation = 'portrait'): DomPdfWrapper
    {
        $pdf = Pdf::loadView($view, ['brandLogo' => $this->branding->logo(), ...$data])
            ->setPaper('letter', $orientation);

        return $this->restrict($pdf);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, string $orientation = 'portrait'): string
    {
        return $this->make($view, $data, $orientation)->output();
    }

    /**
     * HTML ya armado (solo para verificar la configuración en los tests).
     */
    public function renderHtml(string $html): string
    {
        return $this->restrict(Pdf::loadHTML($html))->output();
    }

    private function restrict(DomPdfWrapper $pdf): DomPdfWrapper
    {
        $pdf->getDomPDF()->getOptions()
            ->setAllowedProtocols(self::ALLOWED_PROTOCOLS)
            ->setIsRemoteEnabled(false);

        return $pdf;
    }
}
