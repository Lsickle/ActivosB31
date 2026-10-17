<?php

namespace App\Enums;

/**
 * Estado derivado de una entrega (FR-004, D-005); no se guarda:
 * - closed: todos los ítems devueltos.
 * - overdue: préstamo con pendientes y fecha esperada anterior a hoy
 *   (America/Bogota).
 * - open: el resto.
 */
enum DeliveryStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Abierta',
            self::Closed => 'Cerrada',
            self::Overdue => 'Vencida',
        };
    }
}
