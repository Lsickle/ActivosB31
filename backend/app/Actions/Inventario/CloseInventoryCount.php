<?php

namespace App\Actions\Inventario;

use App\Enums\CountStatus;
use App\Enums\MovementType;
use App\Exceptions\InventoryCountClosedException;
use App\Models\InventoryCount;
use App\Models\InventoryCountLine;
use App\Models\InventoryMovement;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cierra un conteo físico (US3, RN-027, RN-037, D-004):
 *
 * 1. Bloquea el conteo (409 si ya se cerró) y exige todos los renglones de
 *    cantidad contados (422 en `lines`).
 * 2. Bloquea las existencias de los tipos contados en la ubicación, en orden
 *    (asset_type_id, location_id) como RecordStockMovement, y recalcula lo
 *    esperado con la existencia actual (US3-4).
 * 3. Registra un ajuste por cada diferencia (contado − esperado) con
 *    count_id, vía RecordStockMovement.
 * 4. Los serializados no encontrados (o sin revisar) quedan con found = false
 *    y solo se reportan: su estado y ubicación no cambian (RN-037).
 *
 * changed_since_open es true si hubo movimientos en la ubicación desde la
 * apertura o si lo esperado cambió (US3-4).
 */
class CloseInventoryCount
{
    public const string UNCOUNTED_MESSAGE = 'Registra la cantidad contada de todos los renglones antes de cerrar.';

    public function __construct(private RecordStockMovement $recordMovement) {}

    /**
     * @return array{count: InventoryCount, changed_since_open: bool}
     *
     * @throws InventoryCountClosedException
     * @throws ValidationException
     */
    public function handle(InventoryCount $count, User $user): array
    {
        return DB::transaction(function () use ($count, $user) {
            $locked = InventoryCount::query()->with('location')->lockForUpdate()->findOrFail($count->id);

            if (! $locked->isOpen()) {
                throw new InventoryCountClosedException;
            }

            $lines = $locked->lines()->with('assetType')->get();
            $quantityLines = $lines->whereNull('asset_id')->sortBy('asset_type_id')->values();

            if ($quantityLines->contains(fn (InventoryCountLine $line) => $line->counted_quantity === null)) {
                throw ValidationException::withMessages(['lines' => [self::UNCOUNTED_MESSAGE]]);
            }

            $current = $this->lockStock($quantityLines->pluck('asset_type_id')->all(), $locked->location_id);

            $changedSinceOpen = InventoryMovement::query()
                ->where(fn (Builder $query) => $query
                    ->where('from_location_id', $locked->location_id)
                    ->orWhere('to_location_id', $locked->location_id))
                // Con microsegundos: el binding de un Carbon se truncaría al segundo.
                ->where('created_at', '>=', $locked->opened_at->format('Y-m-d H:i:s.uP'))
                ->exists();

            foreach ($quantityLines as $line) {
                $expected = $current[$line->asset_type_id] ?? 0;
                $changedSinceOpen = $changedSinceOpen || $expected !== $line->expected_quantity;
                $difference = $line->counted_quantity - $expected;

                $line->forceFill(['expected_quantity' => $expected])->save();

                if ($difference !== 0) {
                    $this->recordMovement->handle(
                        MovementType::Adjustment,
                        $line->assetType,
                        abs($difference),
                        $difference < 0 ? $locked->location : null,
                        $difference > 0 ? $locked->location : null,
                        "Conteo físico #{$locked->id}",
                        $user,
                        countId: $locked->id,
                    );
                }
            }

            // RN-037: no encontrado = solo se reporta.
            $locked->lines()->whereNotNull('asset_id')->whereNull('found')->update(['found' => false]);

            $locked->forceFill([
                'status' => CountStatus::Closed,
                'closed_by' => $user->id,
                'closed_at' => now(),
            ])->save();

            return ['count' => $locked, 'changed_since_open' => $changedSinceOpen];
        });
    }

    /**
     * Crea las filas de existencia que falten (0) y las bloquea en orden para
     * que lo esperado no cambie hasta registrar los ajustes.
     *
     * @param  list<int>  $assetTypeIds  ordenados de menor a mayor
     * @return array<int, int> asset_type_id => existencia
     */
    private function lockStock(array $assetTypeIds, int $locationId): array
    {
        if ($assetTypeIds === []) {
            return [];
        }

        DB::table('stock_levels')->insertOrIgnore(array_map(fn (int $assetTypeId) => [
            'asset_type_id' => $assetTypeId,
            'location_id' => $locationId,
            'quantity' => 0,
            'updated_at' => now(),
        ], $assetTypeIds));

        return DB::table('stock_levels')
            ->where('location_id', $locationId)
            ->whereIn('asset_type_id', $assetTypeIds)
            ->orderBy('asset_type_id')
            ->orderBy('location_id')
            ->lockForUpdate()
            ->pluck('quantity', 'asset_type_id')
            ->map(fn ($quantity) => (int) $quantity)
            ->all();
    }
}
