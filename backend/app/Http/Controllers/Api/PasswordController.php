<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordSecurityEvent;
use App\Actions\RevokeUserSessions;
use App\Enums\SecurityEventType;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class PasswordController extends Controller
{
    /**
     * Cambio de contraseña propio: cumple el cambio obligatorio de la
     * contraseña temporal (RN-014) y cierra las demás sesiones y tokens del
     * usuario (FR-011), conservando la petición actual. Registra
     * password_changed en la misma transacción (A-1, FR-009).
     */
    public function update(ChangePasswordRequest $request, RevokeUserSessions $revokeSessions, RecordSecurityEvent $recordEvent): JsonResponse
    {
        $user = $request->user();
        $currentToken = $user->currentAccessToken();
        $currentSessionId = $request->hasSession() ? $request->session()->getId() : null;

        DB::transaction(function () use ($request, $user, $currentToken, $currentSessionId, $revokeSessions, $recordEvent) {
            $user->forceFill([
                'password' => $request->validated('password'),
                'must_change_password' => false,
            ])->save();

            $revokeSessions->handle(
                $user,
                exceptSessionId: $currentSessionId,
                exceptTokenId: $currentToken instanceof PersonalAccessToken ? $currentToken->getKey() : null,
            );

            $recordEvent->handle(SecurityEventType::PasswordChanged, $user, $user);
        });

        // Nuevo id para la sesión actual; el anterior se destruye.
        if ($request->hasSession()) {
            $request->session()->regenerate(destroy: true);
        }

        return response()->json(['message' => 'Contraseña actualizada.']);
    }
}
