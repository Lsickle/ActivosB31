<?php

use App\Pdf\Branding;
use App\Pdf\PdfRenderer;
use App\Reports\ReportSection;
use Illuminate\Support\Facades\Log;

require_once __DIR__.'/../../Support/pdf.php';
require_once __DIR__.'/../../Support/deliveries.php';

/**
 * RN-041: todo PDF de la app lleva el logo de la estación arriba a la
 * derecha y como marca de agua centrada en cada página. El logo se lee de
 * config('branding.logo_path') y se incrusta como data:image/png;base64; si
 * falta, el PDF se genera sin logo (un solo warning por petición).
 */
describe('logo de la estación (Branding)', function () {
    it('RN-041: por defecto busca resources/branding/logo-b31.png', function () {
        expect(config('branding.logo_path'))->toBe(resource_path('branding/logo-b31.png'));
    });

    it('RN-041: devuelve el PNG configurado como data URI con sus dimensiones', function () {
        $png = useBrandLogo(brandLogoPng(120, 80));

        $logo = app(Branding::class)->logo();

        expect($logo)->not->toBeNull()
            ->and($logo->dataUri)->toBe('data:image/png;base64,'.base64_encode($png))
            ->and($logo->widthPx)->toBe(120)
            ->and($logo->heightPx)->toBe(80);
    });

    it('RN-041: ajusta el logo a una caja conservando la proporción', function () {
        useBrandLogo(brandLogoPng(120, 80));
        $logo = app(Branding::class)->logo();

        expect($logo->fit(40, 14))->toBe(['width' => 21.0, 'height' => 14.0])
            ->and($logo->fit(30, 100))->toBe(['width' => 30.0, 'height' => 20.0]);
    });

    it('RN-041: sin el archivo devuelve null y registra un solo warning por petición', function () {
        withoutBrandLogo();
        Log::spy();

        expect(app(Branding::class)->logo())->toBeNull()
            ->and(app(Branding::class)->logo())->toBeNull();

        Log::shouldHaveReceived('warning')->once();
    });

    it('RN-041: si el archivo no es un PNG lo ignora con un warning', function (string $contents) {
        $path = tempnam(sys_get_temp_dir(), 'logo-test-');
        file_put_contents($path, $contents);
        config(['branding.logo_path' => $path]);
        Log::spy();

        try {
            expect(app(Branding::class)->logo())->toBeNull();
        } finally {
            @unlink($path);
        }

        Log::shouldHaveReceived('warning')->once();
    })->with([
        'texto' => 'no es una imagen',
        'GIF' => base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='),
        'vacío' => '',
    ]);
});

describe('topes del logo (B-1)', function () {
    it('B-1: ignora un logo de más de 300 KB con un warning', function () {
        // PNG válido con relleno al final: getimagesize lo acepta.
        useBrandLogo(brandLogoPng().str_repeat("\0", 300 * 1024));
        Log::spy();

        expect(app(Branding::class)->logo())->toBeNull();
        Log::shouldHaveReceived('warning')->once();
    });

    it('B-1: ignora un logo de más de 2000 px por lado con un warning', function (int $width, int $height) {
        useBrandLogo(signaturePngHeaderOnly($width, $height));
        Log::spy();

        expect(app(Branding::class)->logo())->toBeNull();
        Log::shouldHaveReceived('warning')->once();
    })->with([[2001, 10], [10, 2001]]);

    it('B-1: acepta un logo de 2000 px por lado', function () {
        useBrandLogo(signaturePngHeaderOnly(2000, 2000));

        expect(app(Branding::class)->logo()?->widthPx)->toBe(2000);
    });

    it('B-1: el warning del logo faltante se registra una sola vez por proceso', function () {
        withoutBrandLogo();
        Log::spy();

        app()->forgetScopedInstances();
        expect(app(Branding::class)->logo())->toBeNull();
        app()->forgetScopedInstances();
        expect(app(Branding::class)->logo())->toBeNull();

        Log::shouldHaveReceived('warning')->once();
    });
});
describe('PdfRenderer', function () {
    it('RN-041: solo permite data:// en tiempo de ejecución; la configuración global sigue sin protocolos', function () {
        $options = app(PdfRenderer::class)->make('reports.pdf', reportViewData())->getDomPDF()->getOptions();

        expect(array_keys($options->getAllowedProtocols()))->toBe(['data://'])
            ->and($options->getIsRemoteEnabled())->toBeFalse()
            ->and($options->getIsPhpEnabled())->toBeFalse()
            ->and($options->getIsJavascriptEnabled())->toBeFalse()
            ->and($options->getChroot())->toBe([resource_path('views/reports')])
            ->and(config('dompdf.options.allowed_protocols'))->toBe([]);
    });

    it('RN-041: dompdf incrusta el logo data: y lo repite en cada página', function () {
        useBrandLogo();
        $rows = array_map(fn (int $i) => ["Fila {$i}", $i], range(1, 160));

        $pdf = app(PdfRenderer::class)
            ->make('reports.pdf', reportViewData([ReportSection::table('Tabla', ['Nombre', 'N.º'], $rows)]))
            ->output(['compress' => 0]);

        $pages = pdfPageCount($pdf);
        expect($pdf)->toStartWith('%PDF-')
            ->and($pages)->toBeGreaterThan(1)
            ->and(pdfImageCount($pdf))->toBeGreaterThanOrEqual(1)
            // Logo y marca de agua en cada página: dos usos de la imagen por página.
            ->and(preg_match_all('/\/I\d+\s+Do\b/', $pdf))->toBe(2 * $pages);
    });

    it('no carga imágenes de archivos locales ni remotas (solo data:)', function () {
        $file = tempnam(sys_get_temp_dir(), 'logo-test-');
        file_put_contents($file, brandLogoPng());

        try {
            $pdf = app(PdfRenderer::class)->renderHtml(
                '<p>x</p><img src="file://'.$file.'"><img src="'.$file.'"><img src="https://example.com/x.png">',
            );
        } finally {
            @unlink($file);
        }

        expect($pdf)->toStartWith('%PDF-')
            ->and(pdfImageCount($pdf))->toBe(0);
    });

    it('sí renderiza una imagen data:image/png', function () {
        $pdf = app(PdfRenderer::class)->renderHtml(
            '<p>x</p><img src="data:image/png;base64,'.base64_encode(brandLogoPng()).'">',
        );

        expect(pdfImageCount($pdf))->toBe(1);
    });

    it('RN-041: sin logo el PDF se genera igual', function () {
        withoutBrandLogo();

        $pdf = app(PdfRenderer::class)->render('reports.pdf', reportViewData());

        expect($pdf)->toStartWith('%PDF-')
            ->and(pdfImageCount($pdf))->toBe(0);
    });
});

/**
 * Datos mínimos de la plantilla reports.pdf.
 *
 * @param  list<ReportSection>  $sections
 * @return array<string, mixed>
 */
function reportViewData(array $sections = []): array
{
    return [
        'title' => 'Prueba',
        'header' => ['Generado' => '2026-10-20 10:00'],
        'sections' => $sections,
        'orientation' => 'portrait',
    ];
}
