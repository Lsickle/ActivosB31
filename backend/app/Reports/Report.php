<?php

namespace App\Reports;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * D-001 (008): un reporte = una consulta. La subclase arma el cuerpo (filas
 * + totales) y las secciones de los archivos a partir de ese mismo arreglo,
 * de modo que el JSON, el Excel y el PDF traen exactamente los mismos datos
 * y el mismo encabezado (ReportMeta).
 */
abstract class Report
{
    /**
     * Zona horaria de la estación: rangos (RN-033), fechas impresas y nombre
     * del archivo.
     */
    public const string LOCAL_TIMEZONE = 'America/Bogota';

    private ?ReportMeta $meta = null;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $payload = null;

    public function __construct(protected readonly User $user) {}

    /**
     * Título impreso en los archivos.
     */
    abstract public function title(): string;

    /**
     * Prefijo del nombre de archivo del contrato (p. ej. "inventario").
     */
    abstract public function fileBaseName(): string;

    /**
     * Filtros aplicados, legibles y en el orden en que se imprimen.
     *
     * @return list<array{label: string, value: string}>
     */
    abstract protected function filters(): array;

    /**
     * Cuerpo del reporte (filas y totales), sin el encabezado.
     *
     * @return array<string, mixed>
     */
    abstract protected function body(): array;

    /**
     * Secciones de los archivos, armadas desde toArray().
     *
     * @param  array<string, mixed>  $data
     * @return list<ReportSection>
     */
    abstract protected function sectionsFrom(array $data): array;

    /**
     * D-005: carta vertical por defecto (fichas).
     *
     * @return 'portrait'|'landscape'
     */
    public function orientation(): string
    {
        return 'portrait';
    }

    public function meta(): ReportMeta
    {
        return $this->meta ??= new ReportMeta(now()->toImmutable(), $this->user, $this->filters());
    }

    /**
     * Lo que devuelve el JSON: encabezado + cuerpo.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->payload ??= [...$this->meta()->toArray(), ...$this->body()];
    }

    /**
     * @return list<ReportSection>
     */
    public function sections(): array
    {
        return $this->sectionsFrom($this->toArray());
    }

    /**
     * Nombre del contrato: "<prefijo>-YYYY-MM-DD.<extensión>" (fecha de
     * generación en America/Bogota).
     */
    public function fileName(string $extension): string
    {
        return $this->fileBaseName().'-'.$this->meta()->generatedAt->setTimezone(self::LOCAL_TIMEZONE)->toDateString().'.'.$extension;
    }

    /**
     * Fecha y hora como se imprime en los archivos (America/Bogota).
     */
    public static function localDateTime(CarbonInterface|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse($value)->setTimezone(self::LOCAL_TIMEZONE)->format('Y-m-d H:i');
    }

    public static function yesNo(bool $value): string
    {
        return $value ? 'Sí' : 'No';
    }
}
