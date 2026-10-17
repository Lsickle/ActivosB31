<?php

namespace App\Http\Controllers\Api;

use App\Actions\EnsureAnotherActiveAdmin;
use App\Actions\RevokeUserSessions;
use App\Http\Controllers\Concerns\TranslatesUniqueViolations;
use App\Http\Controllers\Controller;
use App\Http\Requests\ListUsersRequest;
use App\Http\Requests\ResetUserPasswordRequest;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserResource;
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
    ];

    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        $users = User::query()
            ->with('role')
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

        return UserResource::make($user->load('role'));
    }

    /**
     * RN-014: el usuario nace activo, con contraseña temporal y cambio
     * obligatorio.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = new User($request->safe()->only(['name', 'username', 'email', 'role_id', 'password']));
        $user->forceFill(['is_active' => true, 'must_change_password' => true]);

        $this->translatingUniqueViolations(fn () => $user->save(), self::UNIQUE_CONSTRAINTS);

        return UserResource::make($user->load('role'))->response()->setStatusCode(201);
    }

    /**
     * FR-012: no se puede quitar el rol Administrador al último activo.
     */
    public function update(UpdateUserRequest $request, User $user, EnsureAnotherActiveAdmin $ensureAnotherActiveAdmin): UserResource
    {
        $data = $request->validated();

        $this->translatingUniqueViolations(fn () => DB::transaction(function () use ($data, $user, $ensureAnotherActiveAdmin) {
            if (array_key_exists('role_id', $data) && (int) $data['role_id'] !== $user->role_id) {
                $ensureAnotherActiveAdmin->handle($user);
            }

            $user->update($data);
        }), self::UNIQUE_CONSTRAINTS);

        return UserResource::make($user->forgetPermissions()->load('role'));
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
    ): UserResource {
        $activate = $request->boolean('is_active');

        if (! $activate && $user->is($request->user())) {
            throw new ConflictHttpException('No puedes desactivar tu propia cuenta.');
        }

        DB::transaction(function () use ($activate, $user, $ensureAnotherActiveAdmin) {
            if (! $activate) {
                $ensureAnotherActiveAdmin->handle($user);
            }

            $user->forceFill(['is_active' => $activate])->save();
        });

        if (! $activate) {
            $revokeSessions->handle($user);
        }

        return UserResource::make($user->load('role'));
    }

    /**
     * RN-014 / FR-011: contraseña temporal nueva, cambio obligatorio y cierre
     * de todas las sesiones y tokens del usuario.
     */
    public function resetPassword(ResetUserPasswordRequest $request, User $user, RevokeUserSessions $revokeSessions): JsonResponse
    {
        // RN-018: la propia contraseña se cambia con la actual, no se
        // restablece.
        if ($user->is($request->user())) {
            throw new ConflictHttpException('Para cambiar tu propia contraseña usa «Cambiar contraseña».');
        }

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => true,
        ])->save();

        $revokeSessions->handle($user);

        return response()->json(['message' => 'Contraseña restablecida.']);
    }
}
