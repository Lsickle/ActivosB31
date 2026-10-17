<?php

namespace App\Http\Controllers\Api;

use App\Actions\Activos\RemoveAssetPhoto;
use App\Actions\Activos\StoreAssetPhoto;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activos\StoreAssetPhotoRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Foto del activo (FR-008): subir o reemplazar (borra la anterior) y quitar.
 * Requiere activos.gestionar (FR-007).
 */
class AssetPhotoController extends Controller
{
    public function store(StoreAssetPhotoRequest $request, Asset $asset, StoreAssetPhoto $storePhoto): AssetResource
    {
        $storePhoto->handle($asset, $request->file('photo'), $request->user());

        return AssetController::detail($asset);
    }

    public function destroy(Request $request, Asset $asset, RemoveAssetPhoto $removePhoto): AssetResource
    {
        Gate::authorize('update', $asset);

        $removePhoto->handle($asset, $request->user());

        return AssetController::detail($asset);
    }
}
