<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListRolesRequest;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Requests\UpdateRoleStatusRequest;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Roles (RN-010, FR-013). No se borran: se desactivan. La autorización la
 * hace cada Form Request contra RolePolicy. Cada cambio registra su evento de
 * bitácora en la misma transacción (A-1, FR-009).
 */
class RoleController extends Controller
{
    use TranslatesUniqueViolations;

    /**
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = ['roles_name_lower_unique' => 'name'];

    /**
     * Sin paginar: son pocos registros. Cada rol trae sus permisos para armar
     * la matriz junto con GET /api/permissions.
     */
    public function index(ListRolesRequest $request): AnonymousResourceCollection
    {
        $roles = Role::query()
            ->with('permissions:id,code')
            ->withCount(Role::activeUsersCount())
            ->when($request->validated('search'), function ($query, string $search) {
                $query->where('name', 'ilike', '%'.addcslashes($search, '\\%_').'%');
            })
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('id')
            ->get();

        return RoleResource::collection($roles);
    }

    /**
     * El rol nace sin permisos; se asignan desde la matriz.
     */
    public function store(StoreRoleRequest $request, RecordSecurityEvent $recordEvent): JsonResponse
    {
        $role = $this->translatingUniqueViolations(fn () => DB::transaction(function () use ($request, $recordEvent) {
            $role = Role::query()->create($request->validated());

            $recordEvent->handle(SecurityEventType::RoleCreated, $request->user(), role: $role, metadata: [
                'name' => $role->name,
                'description' => $role->description,
            ]);

            return $role;
        }), self::UNIQUE_CONSTRAINTS);

        return $this->resource($role)->response()->setStatusCode(201);
    }

    /**
     * RN-020: en los roles precargados solo cambia la descripción (lo valida
     * UpdateRoleRequest).
     */
    public function update(UpdateRoleRequest $request, Role $role, RecordSecurityEvent $recordEvent): RoleResource
    {
        $this->translatingUniqueViolations(fn () => DB::transaction(function () use ($request, $role, $recordEvent) {
            $role->fill($request->validated());

            $changes = [];
            foreach (array_keys($role->getDirty()) as $field) {
                $changes[$field] = [$role->getOriginal($field), $role->getAttribute($field)];
            }

            $role->save();

            if ($changes !== []) {
                $recordEvent->handle(SecurityEventType::RoleUpdated, $request->user(), role: $role, metadata: ['changes' => $changes]);
            }
        }), self::UNIQUE_CONSTRAINTS);

        return $this->resource($role);
    }

    /**
     * FR-013: los roles precargados y los que tienen usuarios activos no se
     * desactivan.
     */
    public function updateStatus(UpdateRoleStatusRequest $request, Role $role, RecordSecurityEvent $recordEvent): RoleResource
    {
        $activate = $request->boolean('is_active');

        if (! $activate && $role->is_system) {
            throw new ConflictHttpException('Los roles precargados no se pueden desactivar.');
        }

        DB::transaction(function () use ($request, $activate, $role, $recordEvent) {
            if (! $activate) {
                Role::query()->whereKey($role->getKey())->lockForUpdate()->first();
                $activeUsers = $role->users()->where('is_active', true)->count();

                if ($activeUsers > 0) {
                    throw new ConflictHttpException($activeUsers === 1
                        ? 'El rol tiene 1 usuario activo asignado.'
                        : "El rol tiene {$activeUsers} usuarios activos asignados.");
                }
            }

            $role->forceFill(['is_active' => $activate]);

            if ($role->isDirty('is_active')) {
                $role->save();
                $recordEvent->handle(SecurityEventType::RoleStatusChanged, $request->user(), role: $role, metadata: ['is_active' => $activate]);
            }
        });

        return $this->resource($role);
    }

    private function resource(Role $role): RoleResource
    {
        return RoleResource::make($role->load('permissions:id,code')->loadCount(Role::activeUsersCount()));
    }
}
