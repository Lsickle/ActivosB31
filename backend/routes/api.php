<?php

use App\Http\Controllers\Api\AuthController;
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

        // Módulos de negocio (ver docs/modulos.md): activos, empleados,
        // entregas, inventario, reportes y configuración.
    });
});
