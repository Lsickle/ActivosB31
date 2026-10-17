<?php

namespace App\Http\Controllers\Api;

use App\Actions\Activos\ChangeAssetLocation;
use App\Actions\Activos\ChangeAssetStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activos\UpdateAssetLocationRequest;
use App\Http\Requests\Activos\UpdateAssetStatusRequest;
use App\Http\Resources\AssetResource;
use App\Models\Asset;
use App\Models\Location;

/**
 * Estado y ubicación del activo (US2, D-001). Los conflictos de estado
 * (Asignado, Dado de baja) responden 409 (AssetStateException).
 */
class AssetStateController extends Controller
{
    public function updateStatus(UpdateAssetStatusRequest $request, Asset $asset, ChangeAssetStatus $changeStatus): AssetResource
    {
        $changeStatus->handle($asset, $request->targetStatus(), $request->user(), $request->validated('reason'));

        return AssetController::detail($asset);
    }

    public function updateLocation(UpdateAssetLocationRequest $request, Asset $asset, ChangeAssetLocation $changeLocation): AssetResource
    {
        $changeLocation->handle($asset, Location::query()->findOrFail($request->integer('location_id')), $request->user());

        return AssetController::detail($asset);
    }
}
