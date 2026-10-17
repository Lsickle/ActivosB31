<?php

namespace App\Actions\Inventario;

use App\Enums\AssetStatus;
use App\Enums\TrackingMode;
use App\Models\Asset;
use App\Models\InventoryCount;
use App\Models\Location;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Abre un conteo físico de una ubicación (US3, RN-027, D-004) con los
 * renglones de lo que debería haber:
 * - tipos por cantidad con existencia > 0 en la ubicación (esperado = existencia);
 * - activos serializados con esa ubicación en Disponible o En mantenimiento
 *   (los Asignados están con el empleado, RN-039; los Dados de baja no cuentan).
 *
 * Un solo conteo abierto por ubicación: si dos aperturas pasan la validación
 * a la vez, el índice único parcial rechaza la segunda (422 en location_id).
 */
class OpenInventoryCount
{
    public const string ALREADY_OPEN_MESSAGE = 'Ya hay un conteo abierto en esta ubicación.';

    /**
     * @throws ValidationException
     */
    public function handle(Location $location, ?string $notes, User $user): InventoryCount
    {
        try {
            return DB::transaction(function () use ($location, $notes, $user) {
                $count = new InventoryCount(['location_id' => $location->id, 'notes' => $notes]);
                $count->forceFill(['opened_by' => $user->id, 'opened_at' => now()])->save();

                $stock = StockLevel::query()
                    ->join('asset_types', 'asset_types.id', '=', 'stock_levels.asset_type_id')
                    ->where('stock_levels.location_id', $location->id)
                    ->where('stock_levels.quantity', '>', 0)
                    ->where('asset_types.tracking_mode', TrackingMode::Quantity->value)
                    ->orderBy('asset_types.name')
                    ->orderBy('asset_types.id')
                    ->get(['stock_levels.asset_type_id', 'stock_levels.quantity']);

                $assets = Asset::query()
                    ->join('asset_types', 'asset_types.id', '=', 'assets.asset_type_id')
                    ->where('assets.location_id', $location->id)
                    ->whereIn('assets.status', [AssetStatus::Available->value, AssetStatus::Maintenance->value])
                    ->orderBy('asset_types.name')
                    ->orderBy('assets.plate')
                    ->get(['assets.id', 'assets.asset_type_id']);

                $count->lines()->createMany([
                    ...$stock->map(fn (StockLevel $level) => [
                        'asset_type_id' => $level->asset_type_id,
                        'expected_quantity' => $level->quantity,
                    ]),
                    ...$assets->map(fn (Asset $asset) => [
                        'asset_type_id' => $asset->asset_type_id,
                        'asset_id' => $asset->id,
                    ]),
                ]);

                return $count;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! str_contains($exception->getMessage(), 'inventory_counts_one_open_per_location')) {
                throw $exception;
            }

            throw ValidationException::withMessages(['location_id' => [self::ALREADY_OPEN_MESSAGE]]);
        }
    }
}
