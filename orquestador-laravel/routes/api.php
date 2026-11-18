<?php

use App\Http\Controllers\ResumenProductoController;
use App\Http\Controllers\ResumenSolicitudController;
use Illuminate\Support\Facades\Route;

// Laravel antepone /api a estas rutas (registradas en bootstrap/app.php).
// whereNumber: el identificador solo puede contener dígitos.

// Actividad: orquesta los servicios de usuarios y estados
Route::get('/resumen-solicitud/{id}', ResumenSolicitudController::class)->whereNumber('id');

// Adaptación al proyecto: orquesta el catálogo y el Nodo L5 (servidor de archivos)
Route::get('/resumen-producto/{id}', ResumenProductoController::class)->whereNumber('id');
