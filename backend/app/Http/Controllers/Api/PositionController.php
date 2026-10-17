<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListPositionsRequest;
use App\Http\Requests\Configuracion\StorePositionRequest;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Requests\Configuracion\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Cargos o rangos de los empleados (RN-035, D-001 de 004): catálogo de
 * Configuración con el patrón de 002. No se borran, se desactivan (RN-003).
 * Autorización en cada Form Request (RN-011).
 */
class PositionController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['positions_name_lower_unique' => 'name'];

    public function index(ListPositionsRequest $request): AnonymousResourceCollection
    {
        $positions = Position::query()
            ->search($request->validated('search'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return PositionResource::collection($positions);
    }

    public function show(Position $position): PositionResource
    {
        Gate::authorize('view', $position);

        return PositionResource::make($position);
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = $this->translatingUniqueViolations(
            fn () => Position::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return PositionResource::make($position)->response()->setStatusCode(201);
    }

    public function update(UpdatePositionRequest $request, Position $position): PositionResource
    {
        $this->translatingUniqueViolations(fn () => $position->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return PositionResource::make($position);
    }

    /**
     * US2-1: los empleados que ya tienen el cargo lo conservan; solo deja de
     * ofrecerse en formularios.
     */
    public function updateStatus(UpdateCatalogStatusRequest $request, Position $position): PositionResource
    {
        $position->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return PositionResource::make($position);
    }
}
