<?php

namespace App\Actions\Activos\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * D-002: campos cambiados de un modelo aún sin guardar, en la forma
 * {campo:{from,to}} que se guarda en asset_events.changes. Las fechas se
 * guardan como Y-m-d.
 */
trait DiffsAttributes
{
    /**
     * @param  list<string>|null  $fields  limita el diff a estos campos
     * @return array<string, array{from: mixed, to: mixed}>
     */
    protected function dirtyChanges(Model $model, ?array $fields = null): array
    {
        $changes = [];

        foreach (array_keys($model->getDirty()) as $field) {
            if ($fields !== null && ! in_array($field, $fields, true)) {
                continue;
            }

            $changes[$field] = [
                'from' => self::plainValue($model->getOriginal($field)),
                'to' => self::plainValue($model->getAttribute($field)),
            ];
        }

        return $changes;
    }

    private static function plainValue(mixed $value): mixed
    {
        return $value instanceof CarbonInterface ? $value->toDateString() : $value;
    }
}
