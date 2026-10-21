<?php

namespace App\Reports\Export;

use App\Actions\RecordSecurityEvent;
use App\Enums\SecurityEventType;
use App\Http\Requests\Reportes\ReportRequest;
use App\Pdf\PdfRenderer;
use App\Reports\Report;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\Facades\Excel as ExcelFacade;

/**
 * D-002 (008): responde un reporte como JSON, Excel o PDF. Los archivos se
 * generan en memoria (sin colas, SC-001) a partir del mismo Report (D-001) y
 * se descargan con Content-Disposition: attachment y el nombre del contrato.
 *
 * Cabeceras de las descargas: no se guardan en cachés (pueden traer datos
 * personales, RN-022) ni se reinterpreta el tipo de contenido.
 *
 * R-2 (revisión de seguridad de 008): antes de generar un archivo se cuentan
 * sus filas; por encima del tope (PDF_MAX_ROWS / XLSX_MAX_ROWS) responde 422
 * en `from` sin generarlo, para que una descarga no agote la memoria ni el
 * tiempo del proceso. El JSON no tiene tope. Los topes se inyectan por el
 * constructor (los tests los reducen).
 *
 * R-3: cada archivo generado deja un evento report_exported en la bitácora
 * de seguridad (quién, qué reporte, de quién, formato, filtros y si trae
 * datos personales del empleado). El JSON no se registra.
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

    /**
     * R-2: filas de tabla máximas por archivo. dompdf es el más costoso
     * (SC-001: ~1 000 filas en menos de 10 s).
     */
    public const int PDF_MAX_ROWS = 2000;

    public const int XLSX_MAX_ROWS = 20000;

    public const string TOO_LARGE_MESSAGE = 'El reporte es demasiado grande: acota el rango de fechas o los filtros.';

    public function __construct(
        private readonly RecordSecurityEvent $recordEvent,
        private readonly PdfRenderer $pdfRenderer,
        private readonly int $pdfMaxRows = self::PDF_MAX_ROWS,
        private readonly int $xlsxMaxRows = self::XLSX_MAX_ROWS,
    ) {}

    /**
     * @throws ValidationException si el archivo supera el tope de filas (R-2).
     */
    public function respond(Report $report, ReportRequest $request): JsonResponse|Response
    {
        $format = $request->reportFormat();

        if ($format === self::FORMAT_JSON) {
            return response()->json(['data' => $report->toArray()]);
        }

        $this->ensureWithinLimit($report, $format);

        $response = match ($format) {
            self::FORMAT_XLSX => self::attachment(
                ExcelFacade::raw(new ReportSpreadsheet($report), Excel::XLSX),
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                $report->fileName(self::FORMAT_XLSX),
            ),
            self::FORMAT_PDF => self::attachment(
                $this->pdf($report),
                'application/pdf',
                $report->fileName(self::FORMAT_PDF),
            ),
        };

        $this->recordEvent->handle(SecurityEventType::ReportExported, $request->user(), metadata: [
            'report' => $report->key(),
            'subject_id' => $report->subjectId(),
            'format' => $format,
            'filters' => $request->reportFilters(),
            'personal_data' => $report->containsPersonalData(),
        ]);

        return $response;
    }

    /**
     * @throws ValidationException
     */
    private function ensureWithinLimit(Report $report, string $format): void
    {
        $limit = $format === self::FORMAT_PDF ? $this->pdfMaxRows : $this->xlsxMaxRows;

        if ($report->rowCount() > $limit) {
            throw ValidationException::withMessages(['from' => [self::TOO_LARGE_MESSAGE]]);
        }
    }

    /**
     * D-005: plantilla Blade con tablas y CSS inline, carta vertical u
     * horizontal según el reporte. RN-041: con el logo de la estación (las
     * únicas imágenes, data:// vía PdfRenderer).
     */
    private function pdf(Report $report): string
    {
        return $this->pdfRenderer->render('reports.pdf', [
            'title' => $report->title(),
            'header' => $report->meta()->headerFields(),
            'sections' => $report->sections(),
            'orientation' => $report->orientation(),
        ], $report->orientation());
    }

    /**
     * Descarga de un archivo generado (también el acta de entrega, RN-041):
     * attachment, sin cachés (datos personales, RN-022) y sin reinterpretar el
     * tipo de contenido.
     */
    public static function attachment(string $content, string $contentType, string $fileName): Response
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
