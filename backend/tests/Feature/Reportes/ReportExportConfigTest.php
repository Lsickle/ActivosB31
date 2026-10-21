<?php

use App\Pdf\PdfRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * 008 — T001: dependencias de exportación (RN-032) y configuración segura de
 * dompdf (docs/infraestructura.md): sin recursos remotos, sin PHP ni
 * JavaScript embebido, chroot restringido y fuente con tildes y eñes.
 */
describe('configuración de dompdf', function () {
    it('desactiva recursos remotos, PHP y JavaScript', function () {
        expect(config('dompdf.options.enable_remote'))->toBeFalse()
            ->and(config('dompdf.options.enable_php'))->toBeFalse()
            ->and(config('dompdf.options.enable_javascript'))->toBeFalse()
            ->and(config('dompdf.options.allowed_remote_hosts'))->toBe([]);
    });

    it('limita el acceso a archivos locales a la carpeta de plantillas de reportes (chroot)', function () {
        expect(config('dompdf.options.chroot'))->toBe([resource_path('views/reports')])
            ->and(config('dompdf.options.allowed_protocols'))->toBe([]);
    });

    it('R-7: no permite ningún protocolo (la plantilla no carga recursos) ni sobrescribe la validación de rutas', function () {
        expect(config('dompdf.options.allowed_protocols'))->toBe([])
            ->and(config('dompdf.options'))->not->toHaveKey('artifactPathValidation');

        $options = Pdf::loadHTML('<p>x</p>')->getDomPDF()->getOptions();
        expect($options->getAllowedProtocols())->toBe([]);
    });

    it('RN-041: el único punto que abre un protocolo es PdfRenderer, y solo data:// (sin recursos remotos)', function () {
        $options = app(PdfRenderer::class)->make('reports.pdf', [
            'title' => 'x', 'header' => [], 'sections' => [], 'orientation' => 'portrait',
        ])->getDomPDF()->getOptions();

        expect(array_keys($options->getAllowedProtocols()))->toBe(['data://'])
            ->and($options->getIsRemoteEnabled())->toBeFalse()
            ->and(config('dompdf.options.allowed_protocols'))->toBe([]);
    });

    it('usa DejaVu Sans por defecto (tildes y eñes)', function () {
        expect(config('dompdf.options.default_font'))->toBe('DejaVu Sans');
    });

    it('genera un PDF con DejaVu Sans y no carga imágenes remotas', function () {
        $pdf = Pdf::loadHTML('<p>Señal de tránsito: ÁÉÍÓÚ ñ</p><img src="https://example.com/x.png">')->output();

        expect($pdf)->toStartWith('%PDF-')
            ->and($pdf)->toContain('DejaVuSans');
    });
});

it('instala Laravel Excel sobre PhpSpreadsheet 5', function () {
    expect(class_exists(Excel::class))->toBeTrue()
        ->and(class_exists(Spreadsheet::class))->toBeTrue();
});
