<?php

namespace App\Http\Controllers\Api;

use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListSecurityEventsRequest;
use App\Http\Resources\SecurityEventResource;
use App\Models\SecurityEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Consulta de la bitácora de seguridad (FR-008, D-006): solo lectura, con
 * usuarios.gestionar (RN-019).
 */
class SecurityEventController extends Controller
{
    private const int DEFAULT_PER_PAGE = 25;

    /**
     * Del más reciente al más antiguo; `user_id` coincide con el actor o con
     * el usuario afectado; `from`/`to` son días de America/Bogota inclusivos
     * (la aplicación guarda en UTC).
     */
    public function index(ListSecurityEventsRequest $request): AnonymousResourceCollection
    {
        $events = SecurityEvent::query()
            ->with(['actor:id,name,username', 'targetUser:id,name,username', 'targetRole:id,name'])
            ->when($request->validated('event'), fn (Builder $query, string $event) => $query->where('event', $event))
            ->when($request->filled('user_id'), function (Builder $query) use ($request) {
                $userId = $request->integer('user_id');
                $query->where(fn (Builder $query) => $query->where('actor_id', $userId)->orWhere('target_user_id', $userId));
            })
            ->when($request->validated('from'), fn (Builder $query, string $from) => $query->where(
                'created_at', '>=', Carbon::parse($from, SecurityEvent::DISPLAY_TIMEZONE)->startOfDay()->utc(),
            ))
            ->when($request->validated('to'), fn (Builder $query, string $to) => $query->where(
                'created_at', '<', Carbon::parse($to, SecurityEvent::DISPLAY_TIMEZONE)->addDay()->startOfDay()->utc(),
            ))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', self::DEFAULT_PER_PAGE))
            ->withQueryString();

        return SecurityEventResource::collection($events);
    }

    /**
     * Catálogo de tipos para el filtro (FR-005).
     */
    public function types(): JsonResponse
    {
        Gate::authorize('viewAny', SecurityEvent::class);

        return response()->json([
            'data' => array_map(
                fn (SecurityEventType $type) => ['code' => $type->value, 'label' => $type->label()],
                SecurityEventType::cases(),
            ),
        ]);
    }
}
