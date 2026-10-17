<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListAssetTypesRequest;
use App\Http\Requests\Configuracion\StoreAssetTypeRequest;
use App\Http\Requests\Configuracion\UpdateAssetTypeRequest;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Resources\AssetTypeResource;
use App\Models\AssetType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Tipos de activo (FR-002, RN-007). No se borran, se desactivan (RN-003). La
 * autorización la hace cada Form Request contra AssetTypePolicy (RN-011).
 */
class AssetTypeController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['asset_types_category_name_lower_unique' => 'name'];

    private const string CATEGORY_COLUMNS = 'category:id,name,is_active';

    public function index(ListAssetTypesRequest $request): AnonymousResourceCollection
    {
        $types = AssetType::query()
            ->with(self::CATEGORY_COLUMNS)
            ->search($request->validated('search'))
            ->when($request->filled('asset_category_id'), fn ($query) => $query->where('asset_category_id', $request->integer('asset_category_id')))
            ->when($request->filled('tracking_mode'), fn ($query) => $query->where('tracking_mode', $request->validated('tracking_mode')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return AssetTypeResource::collection($types);
    }

    public function show(AssetType $assetType): AssetTypeResource
    {
        Gate::authorize('view', $assetType);

        return $this->resource($assetType);
    }

    public function store(StoreAssetTypeRequest $request): JsonResponse
    {
        $type = $this->translatingUniqueViolations(
            fn () => AssetType::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return $this->resource($type)->response()->setStatusCode(201);
    }

    public function update(UpdateAssetTypeRequest $request, AssetType $assetType): AssetTypeResource
    {
        $this->translatingUniqueViolations(fn () => $assetType->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return $this->resource($assetType);
    }

    public function updateStatus(UpdateCatalogStatusRequest $request, AssetType $assetType): AssetTypeResource
    {
        $assetType->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return $this->resource($assetType);
    }

    private function resource(AssetType $type): AssetTypeResource
    {
        return AssetTypeResource::make($type->load(self::CATEGORY_COLUMNS));
    }
}
