<?php

namespace App\Enums;

/**
 * Modo de control de un tipo de activo (RN-007): uno a uno (serializado) o
 * por cantidad.
 */
enum TrackingMode: string
{
    case Serialized = 'serialized';
    case Quantity = 'quantity';

    public function label(): string
    {
        return match ($this) {
            self::Serialized => 'Serializado',
            self::Quantity => 'Por cantidad',
        };
    }
}
