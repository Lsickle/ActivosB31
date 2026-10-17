<?php

namespace App\Enums;

/**
 * Eventos del historial del activo (FR-004, US3, D-002). "delivered" y
 * "returned" los registra Entregas (007) mediante AssignAsset/ReleaseAsset.
 * Los planes de mantenimiento registran su alta y sus ediciones (A-1 de la
 * revisión de seguridad de 005).
 */
enum AssetEventType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case StatusChanged = 'status_changed';
    case LocationChanged = 'location_changed';
    case Delivered = 'delivered';
    case Returned = 'returned';
    case MaintenanceDone = 'maintenance_done';
    case Retired = 'retired';
    case MaintenancePlanCreated = 'maintenance_plan_created';
    case MaintenancePlanUpdated = 'maintenance_plan_updated';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Registro',
            self::Updated => 'Edición',
            self::StatusChanged => 'Cambio de estado',
            self::LocationChanged => 'Cambio de ubicación',
            self::Delivered => 'Entrega',
            self::Returned => 'Devolución',
            self::MaintenanceDone => 'Mantenimiento realizado',
            self::Retired => 'Baja',
            self::MaintenancePlanCreated => 'Plan de mantenimiento creado',
            self::MaintenancePlanUpdated => 'Plan de mantenimiento modificado',
        };
    }
}
