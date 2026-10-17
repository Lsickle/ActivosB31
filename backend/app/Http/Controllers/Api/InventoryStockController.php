<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\ListStockRequest;
use App\Http\Resources\StockLevelResource;
use App\Models\StockLevel;
use App\Queries\StockAlerts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Existencias (006, US2, FR-003, FR-005, D-005): artículos por cantidad por
 * tipo y ubicación, resumen de serializados y alertas de stock mínimo
 * (RN-028). Todo exige inventario.ver (StockLevelPolicy).
 */
class InventoryStockController extends Controller
{
    /**
     * Solo filas con existencia > 0. type_total y below_min se calculan sobre
     * el total del tipo en todas las ubicaciones, aunque se filtre por una.
     */
    public function stock(ListStockRequest $request): AnonymousResourceCollection
    {
        $levels = StockLevel::query()
            ->select('stock_levels.*', 'totals.type_total')
            ->joinSub(StockAlerts::typeTotals(), 'totals', 'totals.asset_type_id', '=', 'stock_levels.asset_type_id')
            ->join('asset_types', 'asset_types.id', '=', 'stock_levels.asset_type_id')
            ->join('locations', 'locations.id', '=', 'stock_levels.location_id')
            ->where('stock_levels.quantity', '>', 0)
            ->when($request->filled('asset_category_id'), fn (Builder $query) => $query->where('asset_types.asset_category_id', $request->integer('asset_category_id')))
            ->when($request->filled('asset_type_id'), fn (Builder $query) => $query->where('stock_levels.asset_type_id', $request->integer('asset_type_id')))
            ->when($request->filled('location_id'), fn (Builder $query) => $query->where('stock_levels.location_id', $request->integer('location_id')))
            ->when($request->belowMin(), fn (Builder $query) => $query
                ->whereNotNull('asset_types.min_stock')
                ->whereColumn('totals.type_total', '<', 'asset_types.min_stock'))
            ->with(['assetType:id,name,min_stock,asset_category_id', 'assetType.category:id,name', 'location:id,name,is_active'])
            ->orderBy('asset_types.name')
            ->orderBy('stock_levels.asset_type_id')
            ->orderBy('locations.name')
            ->orderBy('stock_levels.location_id')
            ->paginate($request->perPage())
            ->withQueryString();

        return StockLevelResource::collection($levels);
    }

    /**
     * D-005: activos serializados agrupados por tipo, ubicación y estado
     * (todos los estados; un activo sin ubicación va con location null).
     */
    public function serializedSummary(): JsonResponse
    {
        Gate::authorize('viewAny', StockLevel::class);

        $groups = DB::table('assets')
            ->join('asset_types', 'asset_types.id', '=', 'assets.asset_type_id')
            ->leftJoin('locations', 'locations.id', '=', 'assets.location_id')
            ->select('asset_types.id as asset_type_id', 'asset_types.name as asset_type_name')
            ->addSelect('locations.id as location_id', 'locations.name as location_name', 'assets.status')
            ->selectRaw('count(*) as count')
            ->groupBy('asset_types.id', 'locations.id', 'assets.status')
            ->orderBy('asset_types.name')
            ->orderBy('asset_types.id')
            ->orderByRaw('locations.name asc nulls last')
            ->orderBy('locations.id')
            ->orderBy('assets.status')
            ->get();

        return response()->json(['data' => $groups->map(fn (object $group) => [
            'asset_type' => ['id' => $group->asset_type_id, 'name' => $group->asset_type_name],
            'location' => $group->location_id === null ? null : ['id' => $group->location_id, 'name' => $group->location_name],
            'status' => $group->status,
            'count' => (int) $group->count,
        ])->values()]);
    }

    /**
     * RN-028 / FR-005: tipos por cantidad disponibles (activos, de categoría
     * activa) con stock mínimo y existencia total por debajo de él. La
     * consulta vive en App\Queries\StockAlerts (compartida con Inicio, 008).
     */
    public function alerts(StockAlerts $alerts): JsonResponse
    {
        Gate::authorize('viewAny', StockLevel::class);

        return response()->json(['data' => $alerts->get()]);
    }
}
