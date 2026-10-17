<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeliveryItem;
use App\Models\Employee;
use App\Queries\EmployeeHoldings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * D-007 / US3-2: lo que el empleado tiene asignado o prestado (ítems de sus
 * entregas con pendiente > 0), para su ficha en Empleados. Exige
 * empleados.ver (EmployeePolicy::view), como el resto de la ficha (RN-022).
 */
class EmployeeHoldingController extends Controller
{
    public function index(Employee $employee, EmployeeHoldings $holdings): JsonResponse
    {
        Gate::authorize('view', $employee);

        // Consulta compartida con el reporte por empleado (008).
        $items = $holdings->get($employee);

        return response()->json(['data' => $items->map(fn (DeliveryItem $item) => [
            'delivery_item_id' => $item->id,
            'delivery' => [
                'id' => $item->delivery->id,
                'code' => $item->delivery->code,
                'kind' => $item->delivery->kind->value,
                'kind_label' => $item->delivery->kind->label(),
                'due_on' => $item->delivery->due_on?->toDateString(),
                'status' => $item->delivery->status()->value,
                'delivered_at' => $item->delivery->delivered_at,
            ],
            'asset' => $item->asset?->only(['id', 'plate']),
            'asset_type' => [
                'id' => $item->assetType->id,
                'name' => $item->assetType->name,
                'tracking_mode' => $item->assetType->tracking_mode->value,
            ],
            'quantity' => $item->quantity,
            'pending_quantity' => $item->pending_quantity,
        ])->values()]);
    }
}
