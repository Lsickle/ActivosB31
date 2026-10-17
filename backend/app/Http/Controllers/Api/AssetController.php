<?php

namespace App\Http\Controllers\Api;

use App\Actions\Activos\CreateAsset;
use App\Actions\Activos\StoreAssetPhoto;
use App\Actions\Activos\UpdateAsset;
use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activos\ListAssetsRequest;
use App\Http\Requests\Activos\StoreAssetRequest;
use App\Http\Requests\Activos\UpdateAssetRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

/**
 * Activos serializados (005, FR-001, FR-007): ver con activos.ver y
 * modificar con activos.gestionar (AssetPolicy). No se borran (sin DELETE).
 */
class AssetController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    public const array UNIQUE_CONSTRAINTS = ['assets_plate_lower_unique' => 'plate'];

    /**
     * @var list<string>
     */
    public const array RELATIONS = [
        'assetType:id,name,tracking_mode,is_active,asset_category_id',
        'assetType.category:id,name,is_active',
        'location:id,name,is_active',
        'holder:id,first_name,last_name,position_id',
        'holder.position:id,name',
    ];

    public function index(ListAssetsRequest $request): AnonymousResourceCollection
    {
        $assets = Asset::query()
            ->with(self::RELATIONS)
            ->withNextMaintenance()
            ->search($request->validated('search'))
            ->when($request->filled('asset_category_id'), fn (Builder $query) => $query->whereHas(
                'assetType',
                fn (Builder $type) => $type->where('asset_category_id', $request->integer('asset_category_id')),
            ))
            ->when($request->filled('asset_type_id'), fn (Builder $query) => $query->where('asset_type_id', $request->integer('asset_type_id')))
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->validated('status')))
            ->when($request->filled('location_id'), fn (Builder $query) => $query->where('location_id', $request->integer('location_id')))
            ->when($request->filled('holder_employee_id'), fn (Builder $query) => $query->where('holder_employee_id', $request->integer('holder_employee_id')))
            ->orderBy('plate')
            ->orderBy('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return AssetResource::collection($assets);
    }

    public function show(Asset $asset): AssetResource
    {
        Gate::authorize('view', $asset);

        return self::detail($asset);
    }

    public function store(StoreAssetRequest $request, CreateAsset $createAsset): JsonResponse
    {
        $asset = $this->translatingUniqueViolations(
            fn () => $createAsset->handle(Arr::except($request->validated(), ['photo']), $request->user(), $request->file('photo')),
            self::UNIQUE_CONSTRAINTS,
        );

        return self::detail($asset)->response()->setStatusCode(201);
    }

    /**
     * PATCH (JSON) o POST (multipart, con foto opcional).
     */
    public function update(UpdateAssetRequest $request, Asset $asset, UpdateAsset $updateAsset, StoreAssetPhoto $storePhoto): AssetResource
    {
        // Una imagen inválida se rechaza antes de tocar los datos.
        $encodedPhoto = $request->hasFile('photo') ? $storePhoto->reencode($request->file('photo')) : null;

        $this->translatingUniqueViolations(
            fn () => $updateAsset->handle($asset, Arr::except($request->validated(), ['photo']), $request->user()),
            self::UNIQUE_CONSTRAINTS,
        );

        if ($encodedPhoto !== null) {
            $storePhoto->storeEncoded($asset, $encodedPhoto, $request->user());
        }

        return self::detail($asset);
    }

    /**
     * Ficha del activo: relaciones, próximo mantenimiento y planes (plan.md).
     */
    public static function detail(Asset $asset): AssetResource
    {
        $asset->load([
            ...self::RELATIONS,
            'maintenancePlans' => fn ($plans) => $plans->orderBy('next_due_on')->orderBy('id'),
        ])->loadMin(
            ['maintenancePlans as next_maintenance_on' => fn (Builder $plans) => $plans->where('is_active', true)],
            'next_due_on',
        );

        return AssetResource::make($asset);
    }
}
