<?php

namespace App\Console\Commands;

use App\Actions\RecordSecurityEvent;
use App\Enums\SecurityEventType;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * RN-034 / A-2 (revisión de seguridad de 003): desbloquea una cuenta desde la
 * consola. Es la salida cuando el único Administrador queda bloqueado y nadie
 * puede usar POST /api/users/{id}/unlock (runbook en
 * specs/003-seguridad-bitacora/research.md).
 *
 * Mismo efecto que UserLockController: contador en 0 y sin bloqueo; queda
 * account_unlocked sin actor (no hay usuario autenticado) y con
 * metadata.channel = 'cli'. Si la cuenta no estaba bloqueada no hace nada.
 */
#[Signature('security:unlock {login : Usuario o correo de la cuenta (sin distinguir mayúsculas)}')]
#[Description('Desbloquea una cuenta bloqueada por intentos fallidos (RN-034)')]
class UnlockUser extends Command
{
    public function handle(): int
    {
        $login = Str::lower(trim((string) $this->argument('login')));
        $recordEvent = RecordSecurityEvent::withoutRequest();

        $result = DB::transaction(function () use ($login, $recordEvent) {
            $user = User::query()
                ->whereRaw('LOWER(username) = ?', [$login])
                ->orWhereRaw('LOWER(email) = ?', [$login])
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                return 'missing';
            }

            if (! $user->isLocked()) {
                return 'not_locked';
            }

            $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();

            $recordEvent->handle(
                SecurityEventType::AccountUnlocked,
                actor: null,
                target: $user,
                metadata: ['channel' => 'cli'],
            );

            return 'unlocked';
        });

        return match ($result) {
            'missing' => $this->failWith("No existe una cuenta con el usuario o correo \"{$login}\"."),
            'not_locked' => $this->warnWith("La cuenta \"{$login}\" no está bloqueada; no se hizo ningún cambio."),
            default => $this->succeedWith("Cuenta \"{$login}\" desbloqueada."),
        };
    }

    private function failWith(string $message): int
    {
        $this->components->error($message);

        return self::FAILURE;
    }

    private function warnWith(string $message): int
    {
        $this->components->warn($message);

        return self::SUCCESS;
    }

    private function succeedWith(string $message): int
    {
        $this->components->info($message);

        return self::SUCCESS;
    }
}
