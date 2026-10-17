<?php

namespace App\Reports;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * RN-033: rango de fechas YYYY-MM-DD opcional e inclusivo, por días
 * completos en America/Bogota (como en 006 y 007).
 */
final readonly class DateRange
{
    public function __construct(
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    /**
     * Limita $column (marca de tiempo) al rango.
     */
    public function apply(Builder $query, string $column): void
    {
        if ($this->from !== null) {
            $query->where($column, '>=', Carbon::parse($this->from, Report::LOCAL_TIMEZONE)->startOfDay()->utc());
        }

        if ($this->to !== null) {
            $query->where($column, '<', Carbon::parse($this->to, Report::LOCAL_TIMEZONE)->addDay()->startOfDay()->utc());
        }
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    public function filters(): array
    {
        $filters = [];

        if ($this->from !== null) {
            $filters[] = ['label' => 'Desde', 'value' => $this->from];
        }

        if ($this->to !== null) {
            $filters[] = ['label' => 'Hasta', 'value' => $this->to];
        }

        return $filters;
    }
}
