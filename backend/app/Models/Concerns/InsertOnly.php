<?php

namespace App\Models\Concerns;

use LogicException;

/**
 * Registros de SOLO INSERCIÓN (historial de activos y mantenimientos
 * realizados, FR-004, FR-005, SC-003): el modelo rechaza modificar o borrar
 * y, además, un trigger de PostgreSQL (prevent_row_changes()) rechaza
 * UPDATE/DELETE/TRUNCATE aunque se intenten con SQL directo.
 */
trait InsertOnly
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException(static::IMMUTABLE_MESSAGE);
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new LogicException(static::IMMUTABLE_MESSAGE);
    }
}
