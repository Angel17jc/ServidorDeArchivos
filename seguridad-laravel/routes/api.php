<?php

use App\Http\Controllers\ArchivoController;
use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// Laravel antepone /api a estas rutas.

// Público: solo el inicio de sesión, limitado a 5 intentos por minuto (fuerza bruta)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

// Protegido: exige un token Bearer válido de Sanctum (si falta o no es válido → 401)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // La autorización por rol y propietario se aplica en cada acción (ArchivoPolicy → 403)
    Route::get('/archivos', [ArchivoController::class, 'index']);
    Route::get('/archivos/{archivo}', [ArchivoController::class, 'show'])->whereNumber('archivo');
    Route::post('/archivos', [ArchivoController::class, 'store']);
    Route::delete('/archivos/{archivo}', [ArchivoController::class, 'destroy'])->whereNumber('archivo');
});
