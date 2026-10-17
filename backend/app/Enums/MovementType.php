<?php

namespace App\Enums;

/**
 * Clases de movimiento de inventario (006, D-002). Las cuatro primeras se
 * registran a mano en Inventario; entrega, devolución y pérdida las genera
 * Entregas (007, RN-038).
 *
 * Ubicaciones por clase: entry/return → destino; exit/delivery → origen;
 * transfer → origen y destino distintos; adjustment → exactamente una
 * (destino suma, origen resta); loss → ninguna.
 */
enum MovementType: string
{
    case Entry = 'entry';
    case Exit = 'exit';
    case Transfer = 'transfer';
    case Adjustment = 'adjustment';
    case Delivery = 'delivery';
    case Return = 'return';
    case Loss = 'loss';

    public function label(): string
    {
        return match ($this) {
            self::Entry => 'Entrada',
            self::Exit => 'Salida',
            self::Transfer => 'Traslado',
            self::Adjustment => 'Ajuste',
            self::Delivery => 'Entrega',
            self::Return => 'Devolución',
            self::Loss => 'Pérdida',
        };
    }

    /**
     * Clases que se registran desde POST /api/inventory/movements.
     *
     * @return list<self>
     */
    public static function manual(): array
    {
        return [self::Entry, self::Exit, self::Transfer, self::Adjustment];
    }

    /**
     * FR-001: el motivo es obligatorio en salidas y ajustes.
     */
    public function requiresReason(): bool
    {
        return in_array($this, [self::Exit, self::Adjustment], true);
    }

    /**
     * D-002: ¿la combinación de origen y destino corresponde a la clase?
     */
    public function acceptsLocations(bool $hasFrom, bool $hasTo): bool
    {
        return match ($this) {
            self::Entry, self::Return => ! $hasFrom && $hasTo,
            self::Exit, self::Delivery => $hasFrom && ! $hasTo,
            self::Transfer => $hasFrom && $hasTo,
            self::Adjustment => $hasFrom !== $hasTo,
            self::Loss => ! $hasFrom && ! $hasTo,
        };
    }

    /**
     * D-003: entradas y traslados solo con tipos disponibles y hacia
     * ubicaciones disponibles (activas, de tipo activo).
     */
    public function requiresAvailableTarget(): bool
    {
        return in_array($this, [self::Entry, self::Transfer], true);
    }
}
