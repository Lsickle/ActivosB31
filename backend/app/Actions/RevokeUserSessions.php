<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FR-011 (D-005): cierra las sesiones web (driver `database`), revoca los
 * tokens Bearer y rota el remember_token de un usuario (B-2), para que una
 * cookie "recordarme" antigua tampoco sirva. En el cambio de contraseña propio
 * se conservan la sesión y el token con que se hizo la petición.
 */
class RevokeUserSessions
{
    public function handle(User $user, ?string $exceptSessionId = null, ?int $exceptTokenId = null): void
    {
        DB::table('sessions')
            ->where('user_id', $user->getKey())
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();

        $user->tokens()
            ->when($exceptTokenId !== null, fn ($query) => $query->whereKeyNot($exceptTokenId))
            ->delete();

        $user->setRememberToken(Str::random(60));
        $user->save();
    }
}
