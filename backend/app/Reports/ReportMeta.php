<?php

namespace App\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Encabezado común de todos los reportes (contrato de 008, D-002): fecha de
 * generación, usuario y filtros aplicados en texto legible, en el orden en
 * que se imprimen. El JSON, el Excel y el PDF usan el mismo objeto.
 */
final readonly class ReportMeta
{
    /**
     * @param  list<array{label: string, value: string}>  $filters
     */
    public function __construct(
        public CarbonImmutable $generatedAt,
        public User $generatedBy,
        public array $filters,
    ) {}

    /**
     * @return array{generated_at: CarbonImmutable, generated_by: array{id: int, name: string}, filters: list<array{label: string, value: string}>}
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'generated_by' => ['id' => $this->generatedBy->id, 'name' => $this->generatedBy->name],
            'filters' => $this->filters,
        ];
    }

    /**
     * Encabezado de los archivos: "Generado" (America/Bogota), "Generado
     * por" y cada filtro.
     *
     * @return array<string, string>
     */
    public function headerFields(): array
    {
        $fields = [
            'Generado' => Report::localDateTime($this->generatedAt),
            'Generado por' => $this->generatedBy->name,
        ];

        foreach ($this->filters as $filter) {
            $fields[$filter['label']] = $filter['value'];
        }

        return $fields;
    }
}
