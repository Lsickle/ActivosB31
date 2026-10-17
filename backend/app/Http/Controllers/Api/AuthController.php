<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
 * Pendiente (siguiente hito, ver CLAUDE.md "Orden de arranque"): bloqueo por
 * intentos fallidos, historial de contraseñas, cambio de contraseña y
 * bitácora de seguridad — no se inventan umbrales aquí sin reglas de negocio
 * confirmadas.
 */
class AuthController extends Controller
{
    private const MOBILE_TOKEN_TTL_DAYS = 14;

    /**
     * Hash bcrypt de una contraseña descartable: se verifica contra él cuando
     * el usuario no existe, para que la respuesta tarde lo mismo y no permita
     * distinguir usuarios válidos por tiempo.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$1KEBLu5VyTefeFDuOB2zR.pYZ7zd9ZPHuCXL36S256cN1qjDJ.f5G';

    public function login(Request $request): JsonResponse
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

        // Mismo mensaje para usuario inexistente, contraseña errada y cuenta
        // inactiva: no revelar qué cuentas existen ni confirmar la contraseña
        // de una cuenta desactivada.
        if (! $user || ! $passwordMatches || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => ['Credenciales inválidas.']]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        if ($request->filled('device_name')) {
            $token = $user->createToken(
                $credentials['device_name'],
                ['*'],
                now()->addDays(self::MOBILE_TOKEN_TTL_DAYS),
            )->plainTextToken;

            return response()->json([
                'user' => $this->userPayload($user),
                'token' => $token,
            ]);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $this->userPayload($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        // currentAccessToken() es un TransientToken para requests de sesión
        // web; solo el caso Bearer trae una fila real que se pueda borrar.
        $token = $request->user()?->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
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
     * @return array<string, mixed>
     */
    private function userPayload(User $user): array
    {
        // `permissions` queda vacío hasta implementar el módulo de usuarios,
        // roles y permisos — el frontend ya lo consume para ocultar menús.
        return $user->only(['id', 'name', 'username', 'email']) + ['permissions' => []];
    }
}
