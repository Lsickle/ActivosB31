<?php

namespace App\Http\Controllers\Api;

use App\Actions\Entregas\CreateDelivery;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Entregas\ListDeliveriesRequest;
use App\Http\Requests\Entregas\StoreDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Location;
use App\Queries\OverdueDeliveryAlerts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Entregas (007, FR-001, FR-005, FR-006): ver con entregas.ver y registrar
 * con entregas.gestionar (DeliveryPolicy). Inmutables: sin edición ni
 * borrado (US1-6).
 */
class DeliveryController extends Controller
{
    public function index(ListDeliveriesRequest $request): AnonymousResourceCollection
    {
        $deliveries = Delivery::query()
            ->with(DeliveryResource::RELATIONS)
            ->search($request->validated('search'))
            ->when($request->filled('employee_id'), fn (Builder $query) => $query->where('employee_id', $request->integer('employee_id')))
            ->when($request->filled('kind'), fn (Builder $query) => $query->where('kind', $request->validated('kind')))
            ->when($request->filled('status'), fn (Builder $query) => $query->withStatus(DeliveryStatus::from($request->validated('status'))))
            // Días completos en la zona horaria de la estación.
            ->when($request->filled('from'), fn (Builder $query) => $query->where(
                'delivered_at', '>=', Carbon::parse($request->validated('from'), Delivery::LOCAL_TIMEZONE)->startOfDay()->utc(),
            ))
            ->when($request->filled('to'), fn (Builder $query) => $query->where(
                'delivered_at', '<', Carbon::parse($request->validated('to'), Delivery::LOCAL_TIMEZONE)->addDay()->startOfDay()->utc(),
            ))
            ->orderByDesc('delivered_at')
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString();

        return DeliveryResource::collection($deliveries);
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        Gate::authorize('view', $delivery);

        return DeliveryResource::make($delivery->load(DeliveryResource::RELATIONS));
    }

    public function store(StoreDeliveryRequest $request, CreateDelivery $createDelivery): JsonResponse
    {
        $delivery = $createDelivery->handle(
            Employee::query()->findOrFail($request->integer('employee_id')),
            $request->kind(),
            $request->validated('due_on'),
            Location::query()->findOrFail($request->integer('from_location_id')),
            $request->validated('notes'),
            $request->signaturePng(),
            $request->items(),
            $request->user(),
        );

        return DeliveryResource::make($delivery->load(DeliveryResource::RELATIONS))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * FR-005 / RN-026 / US3-1: préstamos vencidos con pendientes (también
     * para las alertas de Inicio), del vencimiento más antiguo al más
     * reciente. Sin paginar. La consulta vive en App\Queries\OverdueDeliveryAlerts
     * (compartida con el tablero de Inicio, 008).
     */
    public function alerts(OverdueDeliveryAlerts $alerts): JsonResponse
    {
        Gate::authorize('viewAny', Delivery::class);

        return response()->json(['data' => $alerts->get()]);
    }
}
