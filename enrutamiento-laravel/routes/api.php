<?php

use App\Http\Controllers\EventoController;
use Illuminate\Support\Facades\Route;

// Laravel antepone /api a estas rutas.

// Productor: validación → Message Filter → Content-Based Router → RabbitMQ
Route::post('/eventos', [EventoController::class, 'recibir']);

// Seguimiento: últimos mensajes (o los de un correlation_id) y un mensaje por su message_id
Route::get('/eventos', [EventoController::class, 'listar']);
Route::get('/eventos/{messageId}', [EventoController::class, 'mostrar'])->whereUuid('messageId');
