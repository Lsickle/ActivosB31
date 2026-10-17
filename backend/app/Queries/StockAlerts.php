<?php

namespace App\Queries;

use App\Enums\TrackingMode;
use App\Models\AssetType;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * RN-028 / FR-005 (006): tipos por cantidad disponibles (activos, de
 * categoría activa) con stock mínimo y existencia total por debajo de él.
 *
 * La usan GET /api/inventory/alerts (006) y el tablero de Inicio (008,
 * D-004). typeTotals() es la existencia total por tipo que comparten las
 * existencias de 006 y los reportes de 008 (SC-002).
 */
class StockAlerts
{
    /**
     * @return list<array{asset_type: array{id: int, name: string, category: array{id: int, name: string}}, type_total: int, min_stock: int}>
     */
    public function get(): array
    {
        $types = AssetType::query()
            ->available()
            ->select('asset_types.id', 'asset_types.name', 'asset_types.min_stock', 'asset_types.asset_category_id')
            ->selectRaw('coalesce(totals.type_total, 0) as type_total')
            ->leftJoinSub(self::typeTotals(), 'totals', 'totals.asset_type_id', '=', 'asset_types.id')
            ->where('asset_types.tracking_mode', TrackingMode::Quantity->value)
            ->whereNotNull('asset_types.min_stock')
            ->whereRaw('coalesce(totals.type_total, 0) < asset_types.min_stock')
            ->with('category:id,name')
            ->orderBy('asset_types.name')
            ->orderBy('asset_types.id')
            ->get();

        return $types->map(fn (AssetType $type) => [
            'asset_type' => ['id' => $type->id, 'name' => $type->name, 'category' => $type->category->only(['id', 'name'])],
            'type_total' => (int) $type->type_total,
            'min_stock' => $type->min_stock,
        ])->values()->all();
    }

    /**
     * Existencia total de cada tipo en todas las ubicaciones.
     */
    public static function typeTotals(): QueryBuilder
    {
        return DB::table('stock_levels')
            ->select('asset_type_id')
            ->selectRaw('sum(quantity) as type_total')
            ->groupBy('asset_type_id');
    }
}
