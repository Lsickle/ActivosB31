<?php

namespace App\Http\Controllers\Api;

use App\Actions\Activos\RecordMaintenance;
use App\Actions\Activos\SaveMaintenancePlan;
use App\Http\Controllers\Controller;
use App\Http\Requests\Activos\SaveMaintenancePlanRequest;
use App\Http\Requests\Activos\StoreMaintenanceRecordRequest;
use App\Http\Requests\Configuracion\ListCatalogRequest;
use App\Http\Resources\AssetMaintenancePlanResource;
use App\Http\Resources\AssetMaintenanceRecordResource;
use App\Models\Asset;
use App\Models\AssetMaintenancePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Planes de mantenimiento (RN-029, RN-030, D-003) y mantenimientos
 * realizados (FR-005) de un activo. Ver con activos.ver; crear y editar con
 * activos.gestionar. Los realizados no se editan ni se borran (SC-003). Un
 * activo dado de baja también los admite.
 */
class AssetMaintenanceController extends Controller
{
    public function plans(Asset $asset): AnonymousResourceCollection
    {
        Gate::authorize('view', $asset);

        return AssetMaintenancePlanResource::collection(
            $asset->maintenancePlans()->orderBy('next_due_on')->orderBy('id')->get(),
        );
    }

    /**
     * A-1: el alta queda en el historial del activo (SaveMaintenancePlan).
     */
    public function storePlan(SaveMaintenancePlanRequest $request, Asset $asset, SaveMaintenancePlan $savePlan): JsonResponse
    {
        $plan = $savePlan->create($asset, $request->planData(), $request->user());

        return AssetMaintenancePlanResource::make($plan->refresh())->response()->setStatusCode(201);
    }

    /**
     * A-1: la edición con cambios (incluido activar/desactivar) queda en el
     * historial del activo (SaveMaintenancePlan).
     */
    public function updatePlan(SaveMaintenancePlanRequest $request, Asset $asset, AssetMaintenancePlan $plan, SaveMaintenancePlan $savePlan): AssetMaintenancePlanResource
    {
        // El plan debe ser de este activo.
        abort_unless($plan->asset_id === $asset->id, 404);

        return AssetMaintenancePlanResource::make($savePlan->update($plan, $request->planData(), $request->user()));
    }

    public function records(Request $request, Asset $asset): AnonymousResourceCollection
    {
        Gate::authorize('view', $asset);

        $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $records = $asset->maintenanceRecords()
            ->with(['plan:id,name', 'user:id,name'])
            ->orderByDesc('performed_on')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', ListCatalogRequest::DEFAULT_PER_PAGE))
            ->withQueryString();

        return AssetMaintenanceRecordResource::collection($records);
    }

    public function storeRecord(StoreMaintenanceRecordRequest $request, Asset $asset, RecordMaintenance $recordMaintenance): JsonResponse
    {
        $record = $recordMaintenance->handle($asset, $request->validated(), $request->user());

        return AssetMaintenanceRecordResource::make($record->load(['plan:id,name', 'user:id,name']))
            ->response()
            ->setStatusCode(201);
    }
}
