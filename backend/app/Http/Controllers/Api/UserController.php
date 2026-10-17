<?php

namespace App\Http\Controllers\Api;

use App\Actions\EnsureAnotherActiveAdmin;
use App\Actions\RecordSecurityEvent;
use App\Actions\RevokeUserSessions;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Gestión de usuarios (FR-007): no se borran, se desactivan. Cada Form
 * Request autoriza contra UserPolicy antes de validar el cuerpo, para que un
 * usuario sin permiso reciba 403 y no errores de validación.
 *
 * Bitácora (A-1, FR-009): cada cambio registra su evento en la misma
 * transacción; si la operación falla, no queda evento.
 */
class UserController extends Controller
{
    use TranslatesUniqueViolations;

    private const int DEFAULT_PER_PAGE = 15;

    /**
     * Índices únicos de `users` (FR-008) => campo del formulario.
     *
     * @var array<string, string>
     */
    private const array UNIQUE_CONSTRAINTS = [
        'users_username_lower_unique' => 'username',
        'users_email_lower_unique' => 'email',
        'users_employee_id_unique' => 'employee_id',
    ];

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with(UserResource::RELATIONS)
            ->when($request->validated('search'), function ($query, string $search) {
                $query->whereAny(['name', 'username', 'email'], 'ilike', '%'.addcslashes($search, '\\%_').'%');
            })
            ->when($request->filled('role_id'), fn ($query) => $query->where('role_id', $request->integer('role_id')))
            ->when($request->filled('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($request->integer('per_page', self::DEFAULT_PER_PAGE))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('view', $user);

        return UserResource::make($user->load(UserResource::RELATIONS));
    }

    /**
     * RN-014: el usuario nace activo, con contraseña temporal y cambio
     * obligatorio.
     */
    public function store(StoreUserRequest $request, RecordSecurityEvent $recordEvent): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'username', 'email', 'role_id', 'employee_id', 'password']));
        $user->forceFill(['is_active' => true, 'must_change_password' => true]);

        $this->translatingUniqueViolations(fn () => DB::transaction(function () use ($request, $user, $recordEvent) {
            $user->save();

            $user->load(UserResource::RELATIONS);
            $metadata = ['role' => $user->role->name];

            // RN-015 / A-1: el vínculo se registra con id y nombre del empleado
            // (E-3: el id lo relaciona aunque luego se corrija el nombre).
            if ($user->employee !== null) {
                $metadata['employee'] = ['id' => $user->employee->id, 'name' => $user->employee->full_name];
            }

            $recordEvent->handle(SecurityEventType::UserCreated, $request->user(), $user, metadata: $metadata);
        }), self::UNIQUE_CONSTRAINTS);

        return UserResource::make($user->load(UserResource::RELATIONS))->response()->setStatusCode(201);
    }

    /**
     * FR-012: no se puede quitar el rol Administrador al último activo.
     * A-1: registra los campos que cambiaron (sin evento si no cambió nada).
     */
    public function update(
        UpdateUserRequest $request,
        User $user,
        EnsureAnotherActiveAdmin $ensureAnotherActiveAdmin,
        RecordSecurityEvent $recordEvent,
    ): UserResource {
        $data = $request->validated();

        $this->translatingUniqueViolations(fn () => DB::transaction(function () use ($request, $data, $user, $ensureAnotherActiveAdmin, $recordEvent) {
            if (array_key_exists('role_id', $data) && (int) $data['role_id'] !== $user->role_id) {
                $ensureAnotherActiveAdmin->handle($user);
            }

            $user->fill($data);
            $changes = $this->describeChanges($user);
            $user->save();

            if ($changes !== []) {
                $recordEvent->handle(SecurityEventType::UserUpdated, $request->user(), $user, metadata: ['changes' => $changes]);
            }
        }), self::UNIQUE_CONSTRAINTS);

        return UserResource::make($user->forgetPermissions()->load(UserResource::RELATIONS));
    }

    /**
     * FR-011 / FR-012: desactivar cierra sesiones y tokens; nadie se desactiva
     * a sí mismo ni deja la aplicación sin Administrador activo.
     */
    public function updateStatus(
        UpdateUserStatusRequest $request,
        User $user,
        EnsureAnotherActiveAdmin $ensureAnotherActiveAdmin,
        RevokeUserSessions $revokeSessions,
        RecordSecurityEvent $recordEvent,
    ): UserResource {
        $activate = $request->boolean('is_active');

        if (! $activate && $user->is($request->user())) {
            throw new ConflictHttpException('No puedes desactivar tu propia cuenta.');
        }

        DB::transaction(function () use ($request, $activate, $user, $ensureAnotherActiveAdmin, $recordEvent) {
            if (! $activate) {
                $ensureAnotherActiveAdmin->handle($user);
            }

            $user->forceFill(['is_active' => $activate]);

            if ($user->isDirty('is_active')) {
                $user->save();
                $recordEvent->handle(SecurityEventType::UserStatusChanged, $request->user(), $user, metadata: ['is_active' => $activate]);
            }
        });

        if (! $activate) {
            $revokeSessions->handle($user);
        }

        return UserResource::make($user->load(UserResource::RELATIONS));
    }

    /**
     * RN-014 / FR-011: contraseña temporal nueva, cambio obligatorio y cierre
     * de todas las sesiones y tokens del usuario.
     */
    public function resetPassword(
        ResetUserPasswordRequest $request,
        User $user,
        RevokeUserSessions $revokeSessions,
        RecordSecurityEvent $recordEvent,
    ): JsonResponse {
        // RN-018: la propia contraseña se cambia con la actual, no se
        // restablece.
        if ($user->is($request->user())) {
            throw new ConflictHttpException('Para cambiar tu propia contraseña usa «Cambiar contraseña».');
        }

        DB::transaction(function () use ($request, $user, $revokeSessions, $recordEvent) {
            $user->forceFill([
                'password' => $request->validated('password'),
                'must_change_password' => true,
            ])->save();

            $revokeSessions->handle($user);

            $recordEvent->handle(SecurityEventType::PasswordReset, $request->user(), $user);
        });

        return response()->json(['message' => 'Contraseña restablecida.']);
    }

    /**
     * A-1 / FR-006: campos modificados como {campo: [antes, después]}. El rol
     * y el empleado vinculado (RN-015) se registran por nombre; contraseña y
     * remember_token nunca se incluyen.
     *
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function describeChanges(User $user): array
    {
        $changes = [];

        foreach (array_keys($user->getDirty()) as $field) {
            if (in_array($field, ['password', 'remember_token'], true)) {
                continue;
            }

            if ($field === 'role_id') {
                $previousRoleId = $user->getOriginal('role_id');
                $names = Role::query()->whereKey([$previousRoleId, $user->role_id])->pluck('name', 'id');
                $changes['role'] = [$names[$previousRoleId] ?? null, $names[$user->role_id] ?? null];

                continue;
            }

            if ($field === 'employee_id') {
                $previousEmployeeId = $user->getOriginal('employee_id');
                $names = Employee::query()
                    ->whereKey(array_filter([$previousEmployeeId, $user->employee_id]))
                    ->get(['id', 'first_name', 'last_name'])
                    ->mapWithKeys(fn (Employee $employee) => [$employee->id => ['id' => $employee->id, 'name' => $employee->full_name]]);
                $changes['employee'] = [$names[$previousEmployeeId] ?? null, $names[$user->employee_id] ?? null];

                continue;
            }

            $changes[$field] = [$user->getOriginal($field), $user->getAttribute($field)];
        }

        return $changes;
    }
}
