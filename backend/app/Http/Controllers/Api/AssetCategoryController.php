<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Configuracion\ListAssetCategoriesRequest;
use App\Http\Requests\Configuracion\StoreAssetCategoryRequest;
use App\Http\Requests\Configuracion\UpdateAssetCategoryRequest;
use App\Http\Requests\Configuracion\UpdateCatalogStatusRequest;
use App\Http\Resources\AssetCategoryResource;
use App\Models\AssetCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Categorías de activo (FR-001). No se borran, se desactivan (RN-003). La
 * autorización la hace cada Form Request contra AssetCategoryPolicy (RN-011).
 */
class AssetCategoryController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['asset_categories_name_lower_unique' => 'name'];

    public function index(ListAssetCategoriesRequest $request): AnonymousResourceCollection
    {
        $categories = AssetCategory::query()
            ->withCount('assetTypes')
            ->search($request->validated('search'))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return AssetCategoryResource::collection($categories);
    }

    public function show(AssetCategory $assetCategory): AssetCategoryResource
    {
        Gate::authorize('view', $assetCategory);

        return $this->resource($assetCategory);
    }

    public function store(StoreAssetCategoryRequest $request): JsonResponse
    {
        $category = $this->translatingUniqueViolations(
            fn () => AssetCategory::query()->create($request->validated()),
            self::UNIQUE_CONSTRAINTS,
        );

        return $this->resource($category)->response()->setStatusCode(201);
    }

    public function update(UpdateAssetCategoryRequest $request, AssetCategory $assetCategory): AssetCategoryResource
    {
        $this->translatingUniqueViolations(fn () => $assetCategory->update($request->validated()), self::UNIQUE_CONSTRAINTS);

        return $this->resource($assetCategory);
    }

    /**
     * FR-008: desactivar no desactiva sus tipos; quedan fuera de las opciones
     * disponibles por AssetType::available().
     */
    public function updateStatus(UpdateCatalogStatusRequest $request, AssetCategory $assetCategory): AssetCategoryResource
    {
        $assetCategory->forceFill(['is_active' => $request->boolean('is_active')])->save();

        return $this->resource($assetCategory);
    }

    private function resource(AssetCategory $category): AssetCategoryResource
    {
        return AssetCategoryResource::make($category->loadCount('assetTypes'));
    }
}
