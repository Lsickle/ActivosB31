<?php

namespace App\Http\Controllers\Api;

use App\Actions\RevokeUserSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    /**
     * Cambio de contraseña propio: cumple el cambio obligatorio de la
     * contraseña temporal (RN-014) y cierra las demás sesiones y tokens del
     * usuario (FR-011), conservando la petición actual.
     */
    public function update(ChangePasswordRequest $request, RevokeUserSessions $revokeSessions): JsonResponse
    {
        $user = $request->user();

        $user->forceFill([
            'password' => $request->validated('password'),
            'must_change_password' => false,
        ])->save();

        $currentToken = $user->currentAccessToken();
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        $revokeSessions->handle(
            $user,
            exceptSessionId: $currentSessionId,
            exceptTokenId: $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null,
        );

        // Nuevo id para la sesión actual; el anterior se destruye.
        if ($request->hasSession()) {
            $request->session()->regenerate(destroy: true);
        }

        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}
