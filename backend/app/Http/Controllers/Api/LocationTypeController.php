<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListLocationTypesRequest;
use App\Http\Requests\Configuracion\StoreLocationTypeRequest;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Requests\Configuracion\UpdateLocationTypeRequest;
use App\Http\Resources\LocationTypeResource;
use App\Models\LocationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Tipos de ubicación administrables (RN-004, FR-003). No se borran, se
 * desactivan (RN-003). Autorización en cada Form Request (RN-011).
 */
class LocationTypeController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['location_types_name_lower_unique' => 'name'];

    public function index(ListLocationTypesRequest $request): AnonymousResourceCollection
    {
        $types = LocationType::query()
            ->withCount('locations')
            ->search($request->validated('search'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return LocationTypeResource::collection($types);
    }

    public function show(LocationType $locationType): LocationTypeResource
    {
        Gate::authorize('view', $locationType);

        return $this->resource($locationType);
    }

    public function store(StoreLocationTypeRequest $request): JsonResponse
    {
        $type = $this->translatingUniqueViolations(
            fn () => LocationType::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return $this->resource($type)->response()->setStatusCode(201);
    }

    public function update(UpdateLocationTypeRequest $request, LocationType $locationType): LocationTypeResource
    {
        $this->translatingUniqueViolations(fn () => $locationType->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return $this->resource($locationType);
    }

    /**
     * FR-008: desactivar no desactiva sus ubicaciones; quedan fuera de las
     * opciones disponibles por Location::available().
     */
    public function updateStatus(UpdateCatalogStatusRequest $request, LocationType $locationType): LocationTypeResource
    {
        $locationType->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return $this->resource($locationType);
    }

    private function resource(LocationType $type): LocationTypeResource
    {
        return LocationTypeResource::make($type->loadCount('locations'));
    }
}
