<?php

namespace App\Enums;

/**
 * Catálogo de eventos de la bitácora de seguridad (FR-005, A-1). El código se
 * guarda en `security_events.event`.
 */
enum SecurityEventType: string
{
    case LoginSucceeded = 'login_succeeded';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case AccountLocked = 'account_locked';
    case AccountUnlocked = 'account_unlocked';
    case UserCreated = 'user_created';
    case UserUpdated = 'user_updated';
    case UserStatusChanged = 'user_status_changed';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case RoleCreated = 'role_created';
    case RoleUpdated = 'role_updated';
    case RoleStatusChanged = 'role_status_changed';
    case RolePermissionsUpdated = 'role_permissions_updated';
    case SettingsUpdated = 'settings_updated';
    case ReportExported = 'report_exported';

    public function label(): string
    {
        return match ($this) {
            self::LoginSucceeded => 'Ingreso exitoso',
            self::LoginFailed => 'Ingreso fallido',
            self::Logout => 'Cierre de sesión',
            self::AccountLocked => 'Cuenta bloqueada',
            self::AccountUnlocked => 'Cuenta desbloqueada',
            self::UserCreated => 'Usuario creado',
            self::UserUpdated => 'Usuario editado',
            self::UserStatusChanged => 'Usuario activado/desactivado',
            self::PasswordReset => 'Contraseña restablecida',
            self::PasswordChanged => 'Contraseña cambiada',
            self::RoleCreated => 'Rol creado',
            self::RoleUpdated => 'Rol editado',
            self::RoleStatusChanged => 'Rol activado/desactivado',
            self::RolePermissionsUpdated => 'Matriz de permisos modificada',
            self::SettingsUpdated => 'Ajustes modificados',
            self::ReportExported => 'Reporte exportado',
        };
    }
}
