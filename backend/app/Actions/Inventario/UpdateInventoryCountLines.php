<?php

namespace App\Actions\Inventario;

use App\Exceptions\InventoryCountClosedException;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use Illuminate\Support\Facades\DB;

/**
 * Registra contados y encontrados de un conteo abierto (US3, D-004). Los
 * datos llegan validados (UpdateInventoryCountLinesRequest); aquí se aplican
 * con el conteo bloqueado para no pisar un cierre simultáneo (409 si ya se
 * cerró). Un tipo nuevo se agrega con expected_quantity = 0; si otra petición
 * ya lo agregó, se actualiza su contado.
 */
class UpdateInventoryCountLines
{
    /**
     * @param  list<array{id?: int|null, asset_type_id?: int|null, asset_id?: int|null, counted_quantity?: int|null, found?: bool|null}>  $lines
     *
     * @throws InventoryCountClosedException
     */
    public function handle(InventoryCount $count, array $lines): InventoryCount
    {
        DB::transaction(function () use ($count, $lines) {
            $locked = InventoryCount::query()->lockForUpdate()->findOrFail($count->id);

            if (! $locked->isOpen()) {
                throw new InventoryCountClosedException;
            }

            $existing = $locked->lines()->get()->keyBy('id');

            foreach ($lines as $line) {
                if (isset($line['id'])) {
                    /** @var InventoryCountLine $current */
                    $current = $existing->get((int) $line['id']);

                    if ($current->isSerialized()) {
                        $current->found = (bool) $line['found'];
                    } elseif (array_key_exists('counted_quantity', $line)) {
                        $current->counted_quantity = $line['counted_quantity'];
                    }

                    $current->save();

                    continue;
                }

                $added = InventoryCountLine::query()->firstOrNew(
                    ['count_id' => $locked->id, 'asset_type_id' => (int) $line['asset_type_id'], 'asset_id' => null],
                    ['expected_quantity' => 0],
                );
                $added->counted_quantity = $line['counted_quantity'] ?? null;
                $added->save();
            }
        });

        return $count;
    }
}
