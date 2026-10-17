<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Http\Resources\AssetEventResource;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Historial del activo (US3, FR-004): solo lectura, del más reciente al más
 * antiguo, paginado. Requiere activos.ver.
 */
class AssetEventController extends Controller
{
    public function index(Request $request, Asset $asset): AnonymousResourceCollection
    {
        Gate::authorize('view', $asset);

        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = $asset->events()
            ->with([
                'fromLocation:id,name',
                'toLocation:id,name',
                'employee:id,first_name,last_name',
                'user:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE))
            ->withQueryString();

        return AssetEventResource::collection($events);
    }
}
