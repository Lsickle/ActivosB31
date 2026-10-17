<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Queries\AssetAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Alertas de mantenimientos y vencimientos (US4-4, US5, RN-030, RN-031,
 * D-004). La consulta vive en App\Queries\AssetAlerts, compartida con el
 * tablero de Inicio (008).
 */
class AssetAlertController extends Controller
{
    public function __invoke(AssetAlerts $alerts): JsonResponse
    {
        Gate::authorize('viewAny', Asset::class);

        return response()->json(['data' => $alerts->get()]);
    }
}
