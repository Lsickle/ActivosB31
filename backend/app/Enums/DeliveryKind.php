<?php

namespace App\Enums;

/**
 * Tipo de entrega (RN-023): dotación (asignación permanente) o préstamo con
 * fecha de devolución esperada.
 */
enum DeliveryKind: string
{
    case Dotacion = 'dotacion';
    case Prestamo = 'prestamo';

    public function label(): string
    {
        return match ($this) {
            self::Dotacion => 'Dotación',
            self::Prestamo => 'Préstamo',
        };
    }
}
