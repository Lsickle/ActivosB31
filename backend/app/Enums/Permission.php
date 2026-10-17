<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Catálogo fijo de permisos (D-002): dos niveles por módulo, ver y gestionar
 * (RN-009). Reportes solo tiene "ver" (FR-001). "usuarios" cubre usuarios,
 * roles y la matriz de permisos. La tabla `permissions` se sincroniza desde
 * aquí con PermissionSeeder; no se crean permisos desde la interfaz.
 */
enum Permission: string
{
    case ActivosVer = 'activos.ver';
    case ActivosGestionar = 'activos.gestionar';
    case InventarioVer = 'inventario.ver';
    case InventarioGestionar = 'inventario.gestionar';
    case EntregasVer = 'entregas.ver';
    case EntregasGestionar = 'entregas.gestionar';
    case EmpleadosVer = 'empleados.ver';
    case EmpleadosGestionar = 'empleados.gestionar';
    case ReportesVer = 'reportes.ver';
    case ConfiguracionVer = 'configuracion.ver';
    case ConfiguracionGestionar = 'configuracion.gestionar';
    case UsuariosVer = 'usuarios.ver';
    case UsuariosGestionar = 'usuarios.gestionar';

    public const string ACTION_VIEW = 'ver';

    public const string ACTION_MANAGE = 'gestionar';

    /**
     * Módulos en el orden en que se muestran en la matriz.
     *
     * @return array<string, string>
     */
    public static function modules(): array
    {
        return [
            'activos' => 'Activos',
            'inventario' => 'Inventario',
            'entregas' => 'Entregas',
            'empleados' => 'Empleados',
            'reportes' => 'Reportes',
            'configuracion' => 'Configuración',
            'usuarios' => 'Usuarios y roles',
        ];
    }

    /**
     * RN-019: permisos exclusivos del rol Administrador; no se conceden a
     * otros roles desde la matriz ni cuentan aunque existan en la tabla.
     */
    public function isAdminOnly(): bool
    {
        return $this === self::UsuariosGestionar;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function module(): string
    {
        return Str::before($this->value, '.');
    }

    public function action(): string
    {
        return Str::after($this->value, '.');
    }

    public function moduleLabel(): string
    {
        return self::modules()[$this->module()];
    }

    public function label(): string
    {
        return Str::ucfirst($this->action()).' '.$this->moduleLabel();
    }

    /**
     * Permiso "ver" del mismo módulo: gestionar implica ver (FR-002).
     */
    public function viewPermission(): self
    {
        return self::from($this->module().'.'.self::ACTION_VIEW);
    }
}
