<?php

use App\Http\Controllers\Api\AuthController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('login');

Route::middleware(['auth:sanctum', 'active'])->group(function () {
    Route::get('/user', [AuthController::class, 'me'])->name('user.me');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    // Módulos de negocio (ver docs/modulos.md): activos, empleados,
    // entregas, inventario, reportes y configuración.
});
