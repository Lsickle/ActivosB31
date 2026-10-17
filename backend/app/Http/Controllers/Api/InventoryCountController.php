<?php

namespace App\Http\Controllers\Api;

use App\Actions\Inventario\CloseInventoryCount;
use App\Actions\Inventario\OpenInventoryCount;
use App\Actions\Inventario\UpdateInventoryCountLines;
use App\Http\Controllers\Controller;
use App\Http\Requests\Inventario\ListInventoryCountsRequest;
use App\Http\Requests\Inventario\StoreInventoryCountRequest;
use App\Http\Requests\Inventario\UpdateInventoryCountLinesRequest;
use App\Http\Resources\InventoryCountResource;
use App\Models\InventoryCount;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Conteos físicos por ubicación (006, US3, RN-027, RN-037, D-004): ver con
 * inventario.ver; abrir, registrar renglones y cerrar con
 * inventario.gestionar (InventoryCountPolicy). No se borran.
 */
class InventoryCountController extends Controller
{
    public function index(ListInventoryCountsRequest $request): AnonymousResourceCollection
    {
        $counts = InventoryCount::query()
            ->with(InventoryCountResource::RELATIONS)
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->validated('status')))
            ->when($request->filled('location_id'), fn (Builder $query) => $query->where('location_id', $request->integer('location_id')))
            ->orderByDesc('opened_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return InventoryCountResource::collection($counts);
    }

    public function show(InventoryCount $count): InventoryCountResource
    {
        Gate::authorize('view', $count);

        return self::detail($count);
    }

    public function store(StoreInventoryCountRequest $request, OpenInventoryCount $openCount): JsonResponse
    {
        $count = $openCount->handle(
            Location::query()->findOrFail($request->integer('location_id')),
            $request->validated('notes'),
            $request->user(),
        );

        return self::detail($count)->response()->setStatusCode(201);
    }

    public function updateLines(UpdateInventoryCountLinesRequest $request, InventoryCount $count, UpdateInventoryCountLines $updateLines): InventoryCountResource
    {
        $updateLines->handle($count, $request->validated('lines'));

        return self::detail($count);
    }

    public function close(Request $request, InventoryCount $count, CloseInventoryCount $closeCount): InventoryCountResource
    {
        Gate::authorize('update', $count);

        ['count' => $closed, 'changed_since_open' => $changed] = $closeCount->handle($count, $request->user());

        return self::detail($closed)->withChangedSinceOpen($changed);
    }

    /**
     * Conteo con sus renglones en orden de creación.
     */
    private static function detail(InventoryCount $count): InventoryCountResource
    {
        $count->refresh()->load([
            ...InventoryCountResource::RELATIONS,
            'lines' => fn ($lines) => $lines->orderBy('id'),
            'lines.assetType:id,name',
            'lines.asset:id,plate',
        ]);

        return InventoryCountResource::make($count);
    }
}
