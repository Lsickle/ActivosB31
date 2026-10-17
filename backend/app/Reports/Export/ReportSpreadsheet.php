<?php

namespace App\Reports\Export;

use App\Reports\Report;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Excel de un reporte (RN-032, D-002), en una hoja:
 *
 *   [título]
 *   [Generado, fecha] [Generado por, usuario] [filtro, valor]…   ← ReportMeta
 *   (vacía)
 *   por sección: [título] [encabezados] filas… (vacía)
 *
 * Mismos datos que el JSON (D-001): las filas salen de Report::sections().
 *
 * E-8 (inyección de fórmulas, revisión de seguridad de 004): ningún valor se
 * interpreta como fórmula. Los textos se escriben siempre como texto
 * (DataType::TYPE_STRING) y, si empiezan por =, +, -, @, tabulador o retorno
 * de carro, además con el prefijo de comilla de Excel (quotePrefix), que
 * impide que se conviertan en fórmula al editar la celda. El valor no se
 * altera: sigue coincidiendo con el JSON.
 */
class ReportSpreadsheet implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithEvents, WithStrictNullComparison, WithTitle
{
    /**
     * Caracteres iniciales que una hoja de cálculo podría tomar como fórmula.
     *
     * @var list<string>
     */
    private const array FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Filas (1-based) en negrita: título, títulos de sección y encabezados.
     *
     * @var list<int>
     */
    private array $boldRows = [];

    /**
     * Celdas de etiqueta (columna A) en negrita: encabezado y campos.
     *
     * @var list<int>
     */
    private array $labelRows = [];

    public function __construct(private readonly Report $report) {}

    /**
     * @return list<list<string|int|null>>
     */
    public function array(): array
    {
        $rows = [[$this->report->title()]];
        $this->boldRows = [1];
        $this->labelRows = [];

        foreach ($this->report->meta()->headerFields() as $label => $value) {
            $rows[] = [$label, $value];
            $this->labelRows[] = count($rows);
        }
        $rows[] = [null];

        foreach ($this->report->sections() as $section) {
            $rows[] = [$section->title];
            $this->boldRows[] = count($rows);

            if (! $section->isFields()) {
                $rows[] = $section->columns;
                $this->boldRows[] = count($rows);
            }

            foreach ($section->rows as $row) {
                $rows[] = $row;
                if ($section->isFields()) {
                    $this->labelRows[] = count($rows);
                }
            }
            $rows[] = [null];
        }

        return $rows;
    }

    public function title(): string
    {
        return mb_substr($this->report->title(), 0, 31);
    }

    /**
     * @return array<class-string, callable>
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->getDelegate()->getDelegate();

                foreach ($this->boldRows as $row) {
                    $sheet->getStyle("{$row}:{$row}")->getFont()->setBold(true);
                }
                foreach ($this->labelRows as $row) {
                    $sheet->getStyle("A{$row}")->getFont()->setBold(true);
                }
                $sheet->getStyle('A1')->getFont()->setSize(14);
            },
        ];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        if ($value === null) {
            $cell->setValueExplicit(null, DataType::TYPE_NULL);

            return true;
        }

        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);

            return true;
        }

        $text = (string) $value;
        $cell->setValueExplicit($text, DataType::TYPE_STRING);

        if (self::looksLikeFormula($text)) {
            $cell->getStyle()->setQuotePrefix(true);
        }

        return true;
    }

    public static function looksLikeFormula(string $text): bool
    {
        return $text !== '' && in_array($text[0], self::FORMULA_TRIGGERS, true);
    }
}
