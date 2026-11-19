<?php

use App\Http\Controllers\MiniaturaController;
use Illuminate\Support\Facades\Route;

// Laravel antepone /api a estas rutas.

// Productor: recibe la solicitud, publica en la cola y responde 202 Accepted
Route::post('/miniaturas', [MiniaturaController::class, 'solicitar']);

// Mismo proceso en modo síncrono, para la comparación (el cliente espera el resultado)
Route::post('/miniaturas/sincrono', [MiniaturaController::class, 'sincrono']);

// Seguimiento: por mensaje o por operación completa
Route::get('/procesamientos/{messageId}', [MiniaturaController::class, 'estado'])->whereUuid('messageId');
Route::get('/procesamientos', [MiniaturaController::class, 'porCorrelacion']);
