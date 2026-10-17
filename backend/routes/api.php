<?php

use App\Http\Controllers\Api\AssetCategoryController;
use App\Http\Controllers\Api\AssetTypeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConfigurationOptionsController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\LocationTypeController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RolePermissionController;
use App\Http\Controllers\Api\UserController;
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

        Route::get('/roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('/roles', [RoleController::class, 'store'])->name('roles.store');
        Route::patch('/roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::patch('/roles/{role}/status', [RoleController::class, 'updateStatus'])->name('roles.status.update');
        Route::put('/roles/{role}/permissions', [RolePermissionController::class, 'update'])->name('roles.permissions.update');

        Route::get('/permissions', [PermissionController::class, 'index'])->name('permissions.index');

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

        // FR-010: opciones disponibles para formularios y otros módulos.
        Route::get('/configuracion/options', ConfigurationOptionsController::class)->name('configuracion.options');

        // Módulos de negocio (ver docs/modulos.md): activos, empleados,
        // entregas, inventario y reportes.
    });
});
