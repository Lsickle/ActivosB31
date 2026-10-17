<?php

namespace App\Reports;

/**
 * Bloque de un reporte en los archivos (D-001): una tabla (encabezados y
 * filas) o una lista de campos etiqueta → valor (fichas y totales). Se arma
 * a partir del mismo arreglo que se devuelve como JSON.
 */
final readonly class ReportSection
{
    /**
     * @param  list<string>|null  $columns  null en las secciones de campos
     * @param  list<list<string|int|null>>  $rows
     */
    private function __construct(
        public string $title,
        public ?array $columns,
        public array $rows,
    ) {}

    /**
     * @param  list<string>  $columns
     * @param  list<list<string|int|null>>  $rows
     */
    public static function table(string $title, array $columns, array $rows): self
    {
        return new self($title, $columns, $rows);
    }

    /**
     * @param  array<string, string|int|null>  $fields
     */
    public static function fields(string $title, array $fields): self
    {
        $rows = [];
        foreach ($fields as $label => $value) {
            $rows[] = [$label, $value];
        }

        return new self($title, null, $rows);
    }

    public function isFields(): bool
    {
        return $this->columns === null;
    }
}
