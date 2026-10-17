<?php

namespace App\Enums;

/**
 * Estado de un conteo físico (RN-027, D-004): un solo conteo abierto por
 * ubicación; cerrado es definitivo.
 */
enum CountStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierto',
            self::Closed => 'Cerrado',
        };
    }
}
