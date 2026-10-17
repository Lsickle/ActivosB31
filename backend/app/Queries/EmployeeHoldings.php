<?php

namespace App\Queries;

use App\Models\DeliveryItem;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Collection;

/**
 * D-007 (007) / US2 (008): lo que el empleado tiene asignado o prestado hoy
 * (ítems de sus entregas con pendiente > 0), de la entrega más reciente a la
 * más antigua.
 *
 * La usan GET /api/employees/{employee}/holdings (007, empleados.ver) y el
 * reporte por empleado (008, reportes.ver).
 */
class EmployeeHoldings
{
    /**
     * @return Collection<int, DeliveryItem>
     */
    public function get(Employee $employee): Collection
    {
        return DeliveryItem::query()
            ->select('delivery_items.*')
            ->join('deliveries', 'deliveries.id', '=', 'delivery_items.delivery_id')
            ->where('deliveries.employee_id', $employee->id)
            ->pending()
            ->with([
                'delivery:id,code,kind,due_on,delivered_at',
                'delivery.items:id,delivery_id,quantity,returned_quantity',
                'asset:id,plate',
                'assetType:id,name,tracking_mode',
            ])
            ->orderByDesc('deliveries.delivered_at')
            ->orderByDesc('deliveries.id')
            ->orderBy('delivery_items.id')
            ->get();
    }
}
