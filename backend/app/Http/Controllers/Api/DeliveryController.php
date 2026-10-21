<?php

namespace App\Http\Controllers\Api;

use App\Actions\Entregas\CreateDelivery;
use App\Actions\RecordSecurityEvent;
use App\Enums\DeliveryStatus;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Entregas\ListDeliveriesRequest;
use App\Http\Requests\Entregas\StoreDeliveryRequest;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\Employee;
use App\Models\Location;
use App\Pdf\PdfRenderer;
use App\Queries\OverdueDeliveryAlerts;
use App\Reports\Export\ReportResponder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Entregas (007, FR-001, FR-005, FR-006): ver con entregas.ver y registrar
 * con entregas.gestionar (DeliveryPolicy). Inmutables: sin edición ni
 * borrado (US1-6).
 */
class DeliveryController extends Controller
{
    public const string SIGNATURE_INTEGRITY_MESSAGE = 'La firma de esta entrega no pasó la verificación de integridad; no se puede generar el acta.';

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
     * RN-041: acta PDF de la entrega (artículos, quién entrega y quién recibe
     * con su firma, devoluciones y estado actual), con el logo de la estación.
     *
     * - Permiso: el de ver la firma en la ficha, es decir ver la entrega y
     *   DeliveryPolicy::viewSignature (entregas.gestionar o empleados.ver; M-1).
     * - M-3: si la firma fue alterada o falta, 409 sin generar el PDF y evento
     *   signature_integrity_failed con el usuario que lo intentó (misma
     *   metadata que deliveries:verify-signatures).
     * - RN-022: del empleado solo nombre, cargo y firma (sin documento).
     * - Cada descarga deja delivery_pdf_exported en la bitácora.
     */
    public function pdf(Request $request, Delivery $delivery, PdfRenderer $renderer, RecordSecurityEvent $recordEvent): Response|JsonResponse
    {
        Gate::authorize('view', $delivery);
        Gate::authorize('viewSignature', $delivery);

        // M-1: una sola lectura; se incrustan los mismos bytes verificados.
        ['png' => $signature, 'problem' => $problem] = $delivery->verifiedSignature();

        if ($problem !== null || $signature === null) {
            $recordEvent->handle(SecurityEventType::SignatureIntegrityFailed, $request->user(), metadata: [
                'delivery_id' => $delivery->id,
                'code' => $delivery->code,
                'reason' => $problem ?? 'altered',
            ]);

            return response()->json(['message' => self::SIGNATURE_INTEGRITY_MESSAGE], 409);
        }

        $delivery->load([
            ...array_filter(DeliveryResource::RELATIONS, fn (string $relation) => ! str_starts_with($relation, 'deliveredBy')),
            'deliveredBy:id,name,employee_id',
            'deliveredBy.employee:id,position_id',
            'deliveredBy.employee.position:id,name',
        ]);

        $title = "Acta de entrega {$delivery->code}";

        $content = $renderer->render('deliveries.pdf', [
            'title' => $title,
            'orientation' => 'portrait',
            'delivery' => $delivery,
            'signatureDataUri' => 'data:image/png;base64,'.base64_encode($signature),
            'generatedAt' => now(Delivery::LOCAL_TIMEZONE)->format('Y-m-d H:i'),
            'generatedBy' => $request->user()->name,
        ]);

        $recordEvent->handle(SecurityEventType::DeliveryPdfExported, $request->user(), metadata: [
            'delivery_id' => $delivery->id,
            'code' => $delivery->code,
        ]);

        return ReportResponder::attachment($content, 'application/pdf', "{$delivery->code}.pdf");
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
