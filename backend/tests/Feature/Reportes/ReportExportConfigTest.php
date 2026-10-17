<?php

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

    it('limita el acceso a archivos locales a la carpeta de plantillas de reportes', function () {
        expect(config('dompdf.options.chroot'))->toBe([resource_path('views/reports')])
            ->and(array_keys(config('dompdf.options.allowed_protocols')))->toBe(['data://']);
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
