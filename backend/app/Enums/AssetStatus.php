<?php

namespace App\Enums;

/**
 * Estados de un activo serializado (RN-005). "Asignado" solo lo ponen y
 * quitan las entregas (D-001, App\Actions\Activos\AssignAsset y
 * ReleaseAsset); "Dado de baja" es definitivo.
 */
enum AssetStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case Maintenance = 'maintenance';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Assigned => 'Asignado',
            self::Maintenance => 'En mantenimiento',
            self::Retired => 'Dado de baja',
        };
    }

    /**
     * D-001: transiciones manuales (PATCH /api/assets/{id}/status):
     * Disponible ⇄ En mantenimiento y Disponible / En mantenimiento → Dado de
     * baja.
     */
    public function canTransitionManuallyTo(self $target): bool
    {
        return match ($this) {
            self::Available => in_array($target, [self::Maintenance, self::Retired], true),
            self::Maintenance => in_array($target, [self::Available, self::Retired], true),
            self::Assigned, self::Retired => false,
        };
    }

    /**
     * Estados que se pueden elegir manualmente como destino (D-001).
     *
     * @return list<self>
     */
    public static function manualTargets(): array
    {
        return [self::Available, self::Maintenance, self::Retired];
    }
}
