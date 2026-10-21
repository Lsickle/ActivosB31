<?php

/*
 * Ayudas de los tests de Reportes (008). Se cargan con require_once desde
 * cada archivo de tests/Feature/Reportes (Pest define las funciones de forma
 * global: un único archivo evita redefinirlas).
 */

use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

// capturePdfHtml() y las ayudas del logo viven en pdf.php (RN-041).
require_once __DIR__.'/pdf.php';

/**
 * Carga un .xlsx (binario) con PhpSpreadsheet.
 */
function loadXlsx(string $binary): Spreadsheet
{
    $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
    file_put_contents($path, $binary);

    try {
        return IOFactory::createReader('Xlsx')->load($path);
    } finally {
        @unlink($path);
    }
}

/**
 * Filas de la primera hoja con los valores crudos (sin formato), sin las
 * celdas vacías del final; una fila vacía queda como [].
 *
 * @return list<list<mixed>>
 */
function xlsxRows(string $binary): array
{
    $sheet = loadXlsx($binary)->getSheet(0);
    $rows = [];

    foreach ($sheet->toArray(null, false, false, false) as $row) {
        $row = array_map(fn (mixed $value) => is_float($value) && floor($value) === $value ? (int) $value : $value, $row);

        while ($row !== [] && end($row) === null) {
            array_pop($row);
        }

        $rows[] = array_values($row);
    }

    while ($rows !== [] && end($rows) === []) {
        array_pop($rows);
    }

    return $rows;
}

/**
 * Renglones de una sección de tabla: [título], [encabezados], filas… hasta
 * la siguiente fila vacía.
 *
 * @param  list<list<mixed>>  $rows
 * @return array{header: list<mixed>, rows: list<list<mixed>>}
 */
function xlsxTable(array $rows, string $title): array
{
    $start = array_search([$title], $rows, true);
    expect($start)->not->toBeFalse("No se encontró la sección «{$title}».");

    // Las celdas vacías del final se recortan al leer: se rellenan con null
    // hasta el ancho de los encabezados.
    $width = count($rows[$start + 1]);
    $body = [];
    for ($i = $start + 2; $i < count($rows) && $rows[$i] !== []; $i++) {
        $body[] = array_pad($rows[$i], $width, null);
    }

    return ['header' => $rows[$start + 1], 'rows' => $body];
}

/**
 * Pares etiqueta → valor de una sección de campos: [título], [etiqueta,
 * valor]… hasta la siguiente fila vacía.
 *
 * @param  list<list<mixed>>  $rows
 * @return array<string, mixed>
 */
function xlsxFields(array $rows, string $title): array
{
    $start = array_search([$title], $rows, true);
    expect($start)->not->toBeFalse("No se encontró la sección «{$title}».");

    $fields = [];
    for ($i = $start + 1; $i < count($rows) && $rows[$i] !== []; $i++) {
        $fields[$rows[$i][0]] = $rows[$i][1] ?? null;
    }

    return $fields;
}

/**
 * Encabezado común (ReportMeta): título, fecha de generación, usuario y
 * filtros, hasta la primera fila vacía.
 *
 * @param  list<list<mixed>>  $rows
 * @return array<string, mixed>
 */
function xlsxHeader(array $rows): array
{
    $header = [];
    for ($i = 1; $i < count($rows) && $rows[$i] !== []; $i++) {
        $header[$rows[$i][0]] = $rows[$i][1] ?? null;
    }

    return $header;
}

/**
 * Todas las celdas no vacías del libro, como texto.
 *
 * @return list<string>
 */
function xlsxAllValues(string $binary): array
{
    return collect(xlsxRows($binary))->flatten()->filter(fn (mixed $value) => $value !== null)->map(fn (mixed $value) => (string) $value)->values()->all();
}

/**
 * Fecha y hora ISO 8601 del JSON como se imprime en los archivos (America/Bogota).
 */
function bogotaDateTime(?string $iso): ?string
{
    return $iso === null ? null : Carbon::parse($iso)->setTimezone('America/Bogota')->format('Y-m-d H:i');
}

/**
 * Pide un reporte con Accept: application/json, como el cliente (también al descargar).
 */
function getReport(string $url): TestResponse
{
    return test()->get($url, ['Accept' => 'application/json']);
}
