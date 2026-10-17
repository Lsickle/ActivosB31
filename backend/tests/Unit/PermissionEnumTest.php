<?php

use App\Enums\Permission;

// RN-009: permisos por módulo en dos niveles (ver / gestionar).
it('define los 13 permisos del catálogo (RN-009)', function () {
    expect(Permission::cases())->toHaveCount(13)
        ->and(Permission::values())->toBe([
            'activos.ver', 'activos.gestionar',
            'inventario.ver', 'inventario.gestionar',
            'entregas.ver', 'entregas.gestionar',
            'empleados.ver', 'empleados.gestionar',
            'reportes.ver',
            'configuracion.ver', 'configuracion.gestionar',
            'usuarios.ver', 'usuarios.gestionar',
        ]);
});

it('expone módulo, acción y etiqueta de cada permiso', function () {
    expect(Permission::ActivosGestionar->module())->toBe('activos')
        ->and(Permission::ActivosGestionar->action())->toBe('gestionar')
        ->and(Permission::ActivosGestionar->label())->toBe('Gestionar Activos')
        ->and(Permission::UsuariosVer->label())->toBe('Ver Usuarios y roles')
        ->and(Permission::ConfiguracionVer->moduleLabel())->toBe('Configuración');
});

it('no define gestionar para reportes (FR-001)', function () {
    expect(Permission::tryFrom('reportes.gestionar'))->toBeNull()
        ->and(Permission::ReportesVer->action())->toBe('ver');
});

it('ubica el permiso ver del mismo módulo (gestionar implica ver, FR-002)', function () {
    expect(Permission::EntregasGestionar->viewPermission())->toBe(Permission::EntregasVer)
        ->and(Permission::EntregasVer->viewPermission())->toBe(Permission::EntregasVer);
});

it('lista los módulos en orden con su etiqueta', function () {
    expect(Permission::modules())->toBe([
        'activos' => 'Activos',
        'inventario' => 'Inventario',
        'entregas' => 'Entregas',
        'empleados' => 'Empleados',
        'reportes' => 'Reportes',
        'configuracion' => 'Configuración',
        'usuarios' => 'Usuarios y roles',
    ]);
});
