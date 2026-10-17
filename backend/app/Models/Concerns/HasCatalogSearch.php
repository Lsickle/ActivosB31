<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Comportamiento común de los catálogos de Configuración: filtro de activos
 * y búsqueda por nombre sin distinguir mayúsculas ni tildes (D-003), con
 * translate() nativo de PostgreSQL (sin la extensión unaccent).
 */
trait HasCatalogSearch
{
    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where($this->qualifyColumn('is_active'), true);
    }

    /**
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function search(Builder $query, ?string $term): void
    {
        if ($term === null || $term === '') {
            return;
        }

        $column = $query->getQuery()->getGrammar()->wrap($this->qualifyColumn('name'));

        $query->whereRaw(
            "translate(lower({$column}), 'áéíóúüñ', 'aeiouun') like translate(lower(?), 'áéíóúüñ', 'aeiouun')",
            ['%'.addcslashes($term, '\\%_').'%'],
        );
    }
}
