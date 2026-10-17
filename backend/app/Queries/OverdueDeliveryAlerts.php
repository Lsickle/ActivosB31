<?php

namespace App\Queries;

use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use Illuminate\Support\Facades\DB;

/**
 * FR-005 / RN-026 / US3-1 (007): préstamos vencidos con pendientes, del
 * vencimiento más antiguo al más reciente, sin paginar. El empleado va sin
 * datos personales (RN-022, D-006).
 *
 * La usan GET /api/deliveries/alerts (007) y el tablero de Inicio (008,
 * D-004): misma consulta, misma forma.
 */
class OverdueDeliveryAlerts
{
    /**
     * @return list<array{id: int, code: string, employee: array{id: int, full_name: string, position: string|null}, due_on: string, pending_quantity: int}>
     */
    public function get(): array
    {
        $deliveries = Delivery::query()
            ->select('deliveries.*')
            ->selectSub(
                DB::table('delivery_items')
                    ->selectRaw('sum(quantity - returned_quantity)')
                    ->whereColumn('delivery_items.delivery_id', 'deliveries.id'),
                'pending_total',
            )
            ->overdue()
            ->with(['employee:id,first_name,last_name,position_id', 'employee.position:id,name'])
            ->orderBy('due_on')
            ->orderBy('id')
            ->get();

        return $deliveries->map(fn (Delivery $delivery) => [
            'id' => $delivery->id,
            'code' => $delivery->code,
            'employee' => DeliveryResource::employee($delivery),
            'due_on' => $delivery->due_on->toDateString(),
            'pending_quantity' => (int) $delivery->pending_total,
        ])->values()->all();
    }
}
