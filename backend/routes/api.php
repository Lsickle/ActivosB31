<?php

use App\Http\Controllers\Api\AssetAlertController;
use App\Http\Controllers\Api\AssetCategoryController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\AssetEventController;
use App\Http\Controllers\Api\AssetMaintenanceController;
use App\Http\Controllers\Api\AssetPhotoController;
use App\Http\Controllers\Api\AssetStateController;
use App\Http\Controllers\Api\AssetTypeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ConfigurationOptionsController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryCountController;
use App\Http\Controllers\Api\InventoryMovementController;
use App\Http\Controllers\Api\InventoryStockController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\LocationTypeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\PositionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\SecurityEventController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UserLockController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    // Disponibles aun con la contraseña temporal pendiente de cambio (RN-014).
    Route::get('/user', [AuthController::class, 'me'])->name('user.me');
    Route::put('/user/password', [PasswordController::class, 'update'])
        ->middleware('throttle:password-change')
        ->name('user.password.update');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('password.changed')->group(function () {
        // Usuarios, roles y matriz de permisos (RN-009, RN-010, RN-013, RN-014).
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status.update');
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password.reset');
        // RN-034: desbloqueo manual (D-007).
        Route::post('/users/{user}/unlock', UserLockController::class)->name('users.unlock');

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::patch('/roles/{role}/status', [RoleController::class, 'updateStatus'])->name('roles.status.update');
        Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])->name('roles.permissions.update');

        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');

        // Bitácora de seguridad (A-1, FR-008): solo lectura, usuarios.gestionar (RN-019).
        Route::get('/security-events', [SecurityEventController::class, 'index'])->name('security-events.index');
        Route::get('/security-events/types', [SecurityEventController::class, 'types'])->name('security-events.types');

        // Configuración (002): catálogos sin DELETE, solo se desactivan (RN-003).
        Route::get('/asset-categories', [AssetCategoryController::class, 'index'])->name('asset-categories.index');
        Route::post('/asset-categories', [AssetCategoryController::class, 'store'])->name('asset-categories.store');
        Route::get('/asset-categories/{asset_category}', [AssetCategoryController::class, 'show'])->name('asset-categories.show');
        Route::patch('/asset-categories/{asset_category}', [AssetCategoryController::class, 'update'])->name('asset-categories.update');
        Route::patch('/asset-categories/{asset_category}/status', [AssetCategoryController::class, 'updateStatus'])->name('asset-categories.status.update');

        Route::get('/asset-types', [AssetTypeController::class, 'index'])->name('asset-types.index');
        Route::post('/asset-types', [AssetTypeController::class, 'store'])->name('asset-types.store');
        Route::get('/asset-types/{asset_type}', [AssetTypeController::class, 'show'])->name('asset-types.show');
        Route::patch('/asset-types/{asset_type}', [AssetTypeController::class, 'update'])->name('asset-types.update');
        Route::patch('/asset-types/{asset_type}/status', [AssetTypeController::class, 'updateStatus'])->name('asset-types.status.update');

        Route::get('/location-types', [LocationTypeController::class, 'index'])->name('location-types.index');
        Route::post('/location-types', [LocationTypeController::class, 'store'])->name('location-types.store');
        Route::get('/location-types/{location_type}', [LocationTypeController::class, 'show'])->name('location-types.show');
        Route::patch('/location-types/{location_type}', [LocationTypeController::class, 'update'])->name('location-types.update');
        Route::patch('/location-types/{location_type}/status', [LocationTypeController::class, 'updateStatus'])->name('location-types.status.update');

        Route::get('/locations', [LocationController::class, 'index'])->name('locations.index');
        Route::post('/locations', [LocationController::class, 'store'])->name('locations.store');
        Route::get('/locations/{location}', [LocationController::class, 'show'])->name('locations.show');
        Route::patch('/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::patch('/locations/{location}/status', [LocationController::class, 'updateStatus'])->name('locations.status.update');

        // 004 (D-001): cargos (RN-035) y compañías o turnos (RN-036).
        Route::get('/positions', [PositionController::class, 'index'])->name('positions.index');
        Route::post('/positions', [PositionController::class, 'store'])->name('positions.store');
        Route::get('/positions/{position}', [PositionController::class, 'show'])->name('positions.show');
        Route::patch('/positions/{position}', [PositionController::class, 'update'])->name('positions.update');
        Route::patch('/positions/{position}/status', [PositionController::class, 'updateStatus'])->name('positions.status.update');

        Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
        Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
        Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
        Route::patch('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
        Route::patch('/companies/{company}/status', [CompanyController::class, 'updateStatus'])->name('companies.status.update');

        // 005 (D-005): ajustes generales (anticipación de vencimientos, RN-030).
        Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
        Route::put('/settings', [SettingsController::class, 'update'])->name('settings.update');

        // FR-010: opciones disponibles para formularios y otros módulos.
        Route::get('/configuracion/options', ConfigurationOptionsController::class)->name('configuracion.options');

        // Empleados (004, RN-021, RN-022): sin DELETE, solo se desactivan.
        // D-004: /employees/options va antes de /employees/{employee}.
        Route::get('/employees/options', [EmployeeController::class, 'options'])->name('employees.options');
        Route::get('/employees', [EmployeeController::class, 'index'])->name('employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('employees.store');
        Route::get('/employees/{employee}', [EmployeeController::class, 'show'])->name('employees.show');
        Route::patch('/employees/{employee}', [EmployeeController::class, 'update'])->name('employees.update');
        Route::patch('/employees/{employee}/status', [EmployeeController::class, 'updateStatus'])->name('employees.status.update');

        // Activos (005, FR-007): sin DELETE (FR-001). /assets/alerts va antes
        // de /assets/{asset}.
        Route::get('/assets', [AssetController::class, 'index'])->name('assets.index');
        Route::get('/assets/alerts', AssetAlertController::class)->name('assets.alerts');
        Route::post('/assets', [AssetController::class, 'store'])->name('assets.store');
        Route::get('/assets/{asset}', [AssetController::class, 'show'])->whereNumber('asset')->name('assets.show');
        Route::match(['patch', 'post'], '/assets/{asset}', [AssetController::class, 'update'])->whereNumber('asset')->name('assets.update');
        Route::post('/assets/{asset}/photo', [AssetPhotoController::class, 'store'])->name('assets.photo.store');
        Route::delete('/assets/{asset}/photo', [AssetPhotoController::class, 'destroy'])->name('assets.photo.destroy');
        Route::patch('/assets/{asset}/status', [AssetStateController::class, 'updateStatus'])->name('assets.status.update');
        Route::patch('/assets/{asset}/location', [AssetStateController::class, 'updateLocation'])->name('assets.location.update');
        Route::get('/assets/{asset}/events', [AssetEventController::class, 'index'])->name('assets.events.index');
        Route::get('/assets/{asset}/maintenance-plans', [AssetMaintenanceController::class, 'plans'])->name('assets.maintenance-plans.index');
        Route::post('/assets/{asset}/maintenance-plans', [AssetMaintenanceController::class, 'storePlan'])->name('assets.maintenance-plans.store');
        Route::patch('/assets/{asset}/maintenance-plans/{plan}', [AssetMaintenanceController::class, 'updatePlan'])->name('assets.maintenance-plans.update');
        Route::get('/assets/{asset}/maintenance-records', [AssetMaintenanceController::class, 'records'])->name('assets.maintenance-records.index');
        Route::post('/assets/{asset}/maintenance-records', [AssetMaintenanceController::class, 'storeRecord'])->name('assets.maintenance-records.store');

        // Inventario (006, FR-006): existencias, movimientos (sin edición ni
        // borrado, FR-001), conteos físicos (RN-027) y alertas (RN-028).
        Route::get('/inventory/stock', [InventoryStockController::class, 'stock'])->name('inventory.stock');
        Route::get('/inventory/serialized-summary', [InventoryStockController::class, 'serializedSummary'])->name('inventory.serialized-summary');
        Route::get('/inventory/alerts', [InventoryStockController::class, 'alerts'])->name('inventory.alerts');
        Route::get('/inventory/movements', [InventoryMovementController::class, 'index'])->name('inventory.movements.index');
        Route::post('/inventory/movements', [InventoryMovementController::class, 'store'])->name('inventory.movements.store');
        Route::get('/inventory/counts', [InventoryCountController::class, 'index'])->name('inventory.counts.index');
        Route::post('/inventory/counts', [InventoryCountController::class, 'store'])->name('inventory.counts.store');
        Route::get('/inventory/counts/{count}', [InventoryCountController::class, 'show'])->whereNumber('count')->name('inventory.counts.show');
        Route::patch('/inventory/counts/{count}/lines', [InventoryCountController::class, 'updateLines'])->whereNumber('count')->name('inventory.counts.lines.update');
        Route::post('/inventory/counts/{count}/close', [InventoryCountController::class, 'close'])->whereNumber('count')->name('inventory.counts.close');

        // Módulos de negocio (ver docs/modulos.md): entregas y reportes.
    });
});
