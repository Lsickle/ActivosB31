<?php

namespace App\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * RN-034 (FR-001, D-003): cuenta un intento fallido contra una cuenta
 * existente y la bloquea al llegar al umbral de config('auth.lockout').
 *
 * Un solo UPDATE ... RETURNING evalúa el valor guardado (no el del modelo en
 * memoria), así dos fallos simultáneos no pierden incrementos. Mientras la
 * cuenta está bloqueada la fila no se toca (no se extiende el bloqueo ni se
 * incrementa); si el bloqueo ya venció, el contador vuelve a empezar en 1.
 * Los tiempos se pasan desde PHP para que coincidan con now() de la app.
 *
 * B-1 (revisión de seguridad de 003): el login lo ejecuta en todo fallo, también
 * con la cuenta bloqueada (el WHERE no toca la fila) y con una cuenta
 * inexistente (id 0, que nunca existe), para que el tiempo de respuesta no
 * delate en qué caso se está.
 */
class RegisterFailedLogin
{
    /**
     * Id que no corresponde a ninguna cuenta (los ids empiezan en 1).
     */
    private const int NONEXISTENT_USER_ID = 0;

    /**
     * @param  User|null  $user  null si la cuenta no existe: el UPDATE se
     *                           ejecuta igual, sin afectar filas (B-1)
     * @return CarbonImmutable|null fin del bloqueo si este fallo bloqueó la cuenta
     */
    public function handle(?User $user): ?CarbonImmutable
    {
        $now = CarbonImmutable::now();
        // Al segundo: Eloquent lee las fechas sin microsegundos.
        $lockedUntil = $now->addMinutes((int) config('auth.lockout.minutes'))->startOfSecond();
        $format = 'Y-m-d H:i:s.uP';

        $row = DB::selectOne(<<<'SQL'
            UPDATE users SET
                failed_login_attempts = CASE
                    WHEN locked_until IS NOT NULL THEN 1
                    ELSE failed_login_attempts + 1
                END,
                locked_until = CASE
                    WHEN (CASE WHEN locked_until IS NOT NULL THEN 1 ELSE failed_login_attempts + 1 END) >= :max_attempts
                        THEN CAST(:locked_until AS timestamptz)
                    ELSE NULL
                END
            WHERE id = :id
              AND (locked_until IS NULL OR locked_until <= CAST(:now AS timestamptz))
            RETURNING failed_login_attempts, locked_until
            SQL, [
            'max_attempts' => (int) config('auth.lockout.max_attempts'),
            'locked_until' => $lockedUntil->format($format),
            'now' => $now->format($format),
            'id' => $user?->getKey() ?? self::NONEXISTENT_USER_ID,
        ], useReadPdo: false);

        // Sin fila: la cuenta no existe o ya estaba bloqueada (p. ej. por un
        // fallo concurrente). Con fila, $row->locked_until solo es no nulo si este
        // fallo alcanzó el umbral.
        if ($row === null || $row->locked_until === null) {
            return null;
        }

        return $lockedUntil;
    }
}
