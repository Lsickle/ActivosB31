<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Actions\RegisterFailedLogin;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticación con Sanctum: cookie de sesión stateful para el SPA web,
 * token Bearer (personal_access_tokens) para un futuro cliente móvil. El
 * mismo endpoint de login decide el modo según si el cliente manda
 * `device_name` (móvil) o no (web).
 *
 * Bloqueo por intentos fallidos (RN-034) y bitácora de seguridad (A-1).
 */
class AuthController extends Controller
{
    private const MOBILE_TOKEN_TTL_DAYS = 14;

    /**
     * Motivos de un ingreso fallido en la bitácora (`metadata.reason`).
     */
    private const string FAILURE_INVALID = 'invalid';

    private const string FAILURE_LOCKED = 'locked';

    private const string FAILURE_INACTIVE = 'inactive';

    /**
     * Hash bcrypt de una contraseña descartable: se verifica contra él cuando
     * el usuario no existe, para que la respuesta tarde lo mismo y no permita
     * distinguir usuarios válidos por tiempo.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$1KEBLu5VyTefeFDuOB2zR.pYZ7zd9ZPHuCXL36S256cN1qjDJ.f5G';

    /**
     * RN-034 / FR-002 (D-004): siempre se verifica el hash (también contra el
     * ficticio) y después se decide, para que una cuenta inexistente, una
     * contraseña errada, una cuenta inactiva o una bloqueada respondan con el
     * mismo mensaje y en el mismo tiempo.
     */
    public function login(Request $request, RegisterFailedLogin $registerFailedLogin, RecordSecurityEvent $recordEvent): JsonResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'], // username o email
            'password' => ['required', 'string'],
            'device_name' => ['sometimes', 'string', 'max:255'],
        ]);

        $login = Str::lower(trim($credentials['login']));

        $user = User::query()
            ->whereRaw('LOWER(username) = ?', [$login])
            ->orWhereRaw('LOWER(email) = ?', [$login])
            ->first();

        $passwordMatches = Hash::check($credentials['password'], $user?->password ?? self::DUMMY_PASSWORD_HASH);

        $failureReason = match (true) {
            $user === null => self::FAILURE_INVALID,
            $user->isLocked() => self::FAILURE_LOCKED,
            ! $passwordMatches => self::FAILURE_INVALID,
            ! $user->is_active => self::FAILURE_INACTIVE,
            default => null,
        };

        if ($failureReason !== null) {
            $this->registerFailure($user, $failureReason, $credentials['login'], $registerFailedLogin, $recordEvent);

            throw ValidationException::withMessages(['login' => ['Credenciales inválidas.']]);
        }

        $isMobile = $request->filled('device_name');

        $token = DB::transaction(function () use ($user, $isMobile, $credentials, $recordEvent) {
            // FR-003: un ingreso exitoso reinicia el contador de fallos.
            $user->forceFill([
                'last_login_at' => now(),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $recordEvent->handle(
                SecurityEventType::LoginSucceeded,
                actor: $user,
                target: $user,
                metadata: ['channel' => $isMobile ? 'token' : 'web'],
            );

            return $isMobile
                ? $user->createToken(
                    $credentials['device_name'],
                    ['*'],
                    now()->addDays(self::MOBILE_TOKEN_TTL_DAYS),
                )->plainTextToken
                : null;
        });

        if ($token !== null) {
            return response()->json([
                'user' => $this->userPayload($user),
                'token' => $token,
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request, RecordSecurityEvent $recordEvent): JsonResponse
    {
        $user = $request->user();

        // currentAccessToken() es un TransientToken para requests de sesión
        // web; solo el caso Bearer trae una fila real que se pueda borrar.
        $token = $user?->currentAccessToken();

        DB::transaction(function () use ($user, $token, $recordEvent) {
            $recordEvent->handle(SecurityEventType::Logout, actor: $user, target: $user);

            if ($token instanceof PersonalAccessToken) {
                $token->delete();
            }
        });

        if (! $token instanceof PersonalAccessToken) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Sesión cerrada.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userPayload($request->user())]);
    }

    /**
     * FR-005 / FR-006 (D-005): registra el ingreso fallido (nunca la
     * contraseña) y cuenta el fallo (RN-034). Los fallos contra una cuenta
     * inactiva también cuentan. Se confirma antes de responder el 422.
     *
     * B-1: el UPDATE de RegisterFailedLogin se ejecuta en todo fallo (cuenta
     * inexistente, bloqueada o contraseña errada) para que el tiempo de
     * respuesta no distinga los casos; con la cuenta bloqueada o inexistente
     * no cambia ninguna fila.
     */
    private function registerFailure(
        ?User $user,
        string $reason,
        string $loginAttempted,
        RegisterFailedLogin $registerFailedLogin,
        RecordSecurityEvent $recordEvent,
    ): void {
        DB::transaction(function () use ($user, $reason, $loginAttempted, $registerFailedLogin, $recordEvent) {
            $recordEvent->handle(
                SecurityEventType::LoginFailed,
                actor: null,
                target: $user,
                metadata: ['reason' => $reason],
                loginAttempted: $user === null ? self::loggableLoginAttempt($loginAttempted) : null,
            );

            $lockedUntil = $registerFailedLogin->handle($user);

            if ($user !== null && $lockedUntil !== null) {
                $recordEvent->handle(
                    SecurityEventType::AccountLocked,
                    actor: null,
                    target: $user,
                    metadata: ['until' => $lockedUntil->toIso8601String()],
                );
            }
        });
    }

    /**
     * M-3 (D-005): el texto intentado solo se guarda cuando la cuenta no
     * existe (si existe, el evento ya la referencia). Si no tiene forma de
     * usuario (misma regla de 001: ^[a-z0-9._-]+$, 3 a 40 caracteres) ni de
     * correo (≤ 255), se guarda un marcador: suele ser una contraseña
     * escrita en el campo equivocado.
     */
    private static function loggableLoginAttempt(string $loginAttempted): string
    {
        $normalized = Str::lower(trim($loginAttempted));
        $length = mb_strlen($normalized);

        $looksLikeUsername = $length >= 3 && $length <= 40 && preg_match('/^[a-z0-9._-]+$/', $normalized) === 1;
        $looksLikeEmail = $length <= 255 && filter_var($normalized, FILTER_VALIDATE_EMAIL) !== false;

        return $looksLikeUsername || $looksLikeEmail
            ? $loginAttempted
            : '[texto no válido, '.mb_strlen($loginAttempted).' caracteres]';
    }

    /**
     * Usuario actual con su rol y permisos efectivos (FR-014): la interfaz
     * los usa para adaptar menú y acciones; el servidor los vuelve a
     * verificar en cada operación (FR-015).
     *
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        return $user->only(['id', 'name', 'username', 'email']) + [
            'role' => $user->role?->only(['id', 'code', 'name']),
            'permissions' => $user->permissionCodes(),
            'must_change_password' => $user->must_change_password,
        ];
    }
}
