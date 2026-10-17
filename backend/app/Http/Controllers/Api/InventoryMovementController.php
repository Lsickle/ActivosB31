<?php

namespace App\Http\Controllers\Api;

use App\Actions\Inventario\RecordStockMovement;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\ListInventoryMovementsRequest;
use App\Http\Requests\Inventario\StoreInventoryMovementRequest;
use App\Http\Resources\InventoryMovementResource;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\InventoryMovement;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

/**
 * Movimientos de inventario (006, US1, FR-001, FR-003): historial con
 * inventario.ver y registro manual con inventario.gestionar. Sin edición ni
 * borrado: un error se corrige con un movimiento inverso.
 */
class InventoryMovementController extends Controller
{
    public function index(ListInventoryMovementsRequest $request): AnonymousResourceCollection
    {
        $movements = InventoryMovement::query()
            ->with(InventoryMovementResource::RELATIONS)
            ->when($request->filled('type'), fn (Builder $query) => $query->where('type', $request->validated('type')))
            ->when($request->filled('asset_type_id'), fn (Builder $query) => $query->where('asset_type_id', $request->integer('asset_type_id')))
            ->when($request->filled('location_id'), fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('from_location_id', $request->integer('location_id'))
                ->orWhere('to_location_id', $request->integer('location_id'))))
            // Días completos en la zona horaria de la estación (FR-003).
            ->when($request->filled('from'), fn (Builder $query) => $query->where(
                'created_at', '>=', Carbon::parse($request->validated('from'), Asset::LOCAL_TIMEZONE)->startOfDay()->utc(),
            ))
            ->when($request->filled('to'), fn (Builder $query) => $query->where(
                'created_at', '<', Carbon::parse($request->validated('to'), Asset::LOCAL_TIMEZONE)->addDay()->startOfDay()->utc(),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return InventoryMovementResource::collection($movements);
    }

    public function store(StoreInventoryMovementRequest $request, RecordStockMovement $recordMovement): JsonResponse
    {
        $movement = $recordMovement->handle(
            $request->movementType(),
            AssetType::query()->findOrFail($request->integer('asset_type_id')),
            $request->integer('quantity'),
            $request->filled('from_location_id') ? Location::query()->findOrFail($request->integer('from_location_id')) : null,
            $request->filled('to_location_id') ? Location::query()->findOrFail($request->integer('to_location_id')) : null,
            $request->validated('reason'),
            $request->user(),
        );

        return InventoryMovementResource::make($movement->load(InventoryMovementResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }
}
