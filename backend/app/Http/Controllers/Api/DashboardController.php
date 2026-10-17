<?php

namespace App\Http\Controllers\Api;

use App\Enums\AssetStatus;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Delivery;
use App\Models\StockLevel;
use App\Queries\AssetAlerts;
use App\Queries\OverdueDeliveryAlerts;
use App\Queries\StockAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tablero de Inicio (008, US4, D-004). Solo exige sesión; cada sección
 * aparece únicamente si el usuario puede ver su módulo (las mismas Policies
 * de 005, 006 y 007). Las alertas salen de las mismas consultas que
 * GET /api/assets/alerts, /api/inventory/alerts y /api/deliveries/alerts.
 */
class DashboardController extends Controller
{
    public function __invoke(
        Request $request,
        AssetAlerts $assetAlerts,
        StockAlerts $stockAlerts,
        OverdueDeliveryAlerts $overdueDeliveries,
    ): JsonResponse {
        $user = $request->user();
        $data = [];

        if ($user->can('viewAny', Asset::class)) {
            $counts = Asset::query()->toBase()->selectRaw('status, count(*) as count')->groupBy('status')->pluck('count', 'status');
            $byStatus = [];
            foreach (AssetStatus::cases() as $status) {
                $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
            }

            $data['assets'] = [
                'by_status' => $byStatus,
                'total' => array_sum($byStatus),
                'alerts' => $assetAlerts->get(),
            ];
        }

        if ($user->can('viewAny', StockLevel::class)) {
            $data['inventory'] = ['below_min' => $stockAlerts->get()];
        }

        if ($user->can('viewAny', Delivery::class)) {
            $data['deliveries'] = [
                'open_count' => Delivery::query()->withStatus(DeliveryStatus::Open)->count(),
                'overdue_count' => Delivery::query()->withStatus(DeliveryStatus::Overdue)->count(),
                'overdue' => $overdueDeliveries->get(),
            ];
        }

        return response()->json(['data' => (object) $data]);
    }
}
