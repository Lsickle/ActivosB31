<?php

namespace App\Enums;

/**
 * Estado de lo devuelto (RN-026, D-004): bueno vuelve a Disponible (o al
 * stock), dañado pasa a En mantenimiento (por cantidad: pérdida, RN-038) y
 * perdido se da de baja con observación (por cantidad: pérdida).
 */
enum ReturnCondition: string
{
    case Good = 'good';
    case Damaged = 'damaged';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Bueno',
            self::Damaged => 'Dañado',
            self::Lost => 'Perdido',
        };
    }

    /**
     * D-004 / contrato: ¿lo devuelto vuelve a una ubicación? Bueno siempre;
     * dañado solo si es serializado (queda En mantenimiento). Lo perdido y lo
     * dañado por cantidad no vuelven al stock (RN-038).
     */
    public function returnsToLocation(bool $serialized): bool
    {
        return $this === self::Good || ($serialized && $this === self::Damaged);
    }
}
