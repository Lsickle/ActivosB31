<?php

namespace App\Reports\Export;

use App\Reports\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;

/**
 * D-002 (008): responde un reporte como JSON, Excel o PDF. Los archivos se
 * generan en memoria (sin colas, SC-001) a partir del mismo Report (D-001) y
 * se descargan con Content-Disposition: attachment y el nombre del contrato.
 *
 * Cabeceras de las descargas: no se guardan en cachés (pueden traer datos
 * personales, RN-022) ni se reinterpreta el tipo de contenido.
 */
class ReportResponder
{
    public const string FORMAT_JSON = 'json';

    public const string FORMAT_XLSX = 'xlsx';

    public const string FORMAT_PDF = 'pdf';

    /**
     * @var list<string>
     */
    public const array FORMATS = [self::FORMAT_JSON, self::FORMAT_XLSX, self::FORMAT_PDF];

    public function respond(Report $report, string $format): JsonResponse|Response
    {
        return match ($format) {
            self::FORMAT_XLSX => $this->download(
                ExcelFacade::raw(new ReportSpreadsheet($report), Excel::XLSX),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $report->fileName(self::FORMAT_XLSX),
            ),
            self::FORMAT_PDF => $this->download(
                $this->pdf($report),
                'application/pdf',
                $report->fileName(self::FORMAT_PDF),
            ),
            default => response()->json(['data' => $report->toArray()]),
        };
    }

    /**
     * D-005: plantilla Blade con tablas y CSS inline, carta vertical u
     * horizontal según el reporte; sin imágenes (config/dompdf.php).
     */
    private function pdf(Report $report): string
    {
        return Pdf::loadView('reports.pdf', [
            'title' => $report->title(),
            'header' => $report->meta()->headerFields(),
            'sections' => $report->sections(),
            'orientation' => $report->orientation(),
        ])->setPaper('letter', $report->orientation())->output();
    }

    private function download(string $content, string $contentType, string $fileName): Response
    {
        return response($content, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
            'Content-Length' => (string) strlen($content),
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
