<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListLocationsRequest;
use App\Http\Requests\Configuracion\StoreLocationRequest;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Requests\Configuracion\UpdateLocationRequest;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Ubicaciones (FR-004): lista plana con tipo (RN-002). No se borran, se
 * desactivan (RN-003). Autorización en cada Form Request (RN-011).
 */
class LocationController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['locations_name_lower_unique' => 'name'];

    private const string LOCATION_TYPE_COLUMNS = 'locationType:id,name,is_active';

    public function index(ListLocationsRequest $request): AnonymousResourceCollection
    {
        $locations = Location::query()
            ->with(self::LOCATION_TYPE_COLUMNS)
            ->search($request->validated('search'))
            ->when($request->filled('location_type_id'), fn ($query) => $query->where('location_type_id', $request->integer('location_type_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return LocationResource::collection($locations);
    }

    public function show(Location $location): LocationResource
    {
        Gate::authorize('view', $location);

        return $this->resource($location);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        $location = $this->translatingUniqueViolations(
            fn () => Location::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return $this->resource($location)->response()->setStatusCode(201);
    }

    public function update(UpdateLocationRequest $request, Location $location): LocationResource
    {
        $this->translatingUniqueViolations(fn () => $location->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return $this->resource($location);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, Location $location): LocationResource
    {
        $location->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return $this->resource($location);
    }

    private function resource(Location $location): LocationResource
    {
        return LocationResource::make($location->load(self::LOCATION_TYPE_COLUMNS));
    }
}
