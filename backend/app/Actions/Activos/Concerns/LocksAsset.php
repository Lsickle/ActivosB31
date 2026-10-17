<?php

namespace App\Actions\Activos\Concerns;

use App\Models\Asset;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Los cambios de estado, ubicación y poseedor se validan contra la fila
 * bloqueada (SELECT ... FOR UPDATE) dentro de la transacción: dos entregas o
 * cambios simultáneos sobre el mismo activo no pueden pisarse. Al terminar,
 * la instancia del llamador queda sincronizada con la fila guardada.
 */
trait LocksAsset
{
    /**
     * @template TResult
     *
     * @param  Closure(Asset): TResult  $callback
     * @return TResult
     */
    protected function withLockedAsset(Asset $asset, Closure $callback): mixed
    {
        return DB::transaction(function () use ($asset, $callback) {
            $locked = Asset::query()->lockForUpdate()->findOrFail($asset->getKey());

            $result = $callback($locked);

            $asset->setRawAttributes($locked->getAttributes(), true);
            $asset->unsetRelation('location')->unsetRelation('holder');

            return $result;
        });
    }
}
