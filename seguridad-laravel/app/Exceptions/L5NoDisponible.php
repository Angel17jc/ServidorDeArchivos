<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** El Nodo L5 no respondió o rechazó la operación. El cliente recibe un 502 sin detalles internos. */
class L5NoDisponible extends RuntimeException
{
    public function report(): void
    {
        Log::warning('Falla al comunicarse con el Nodo L5', ['motivo' => $this->getMessage()]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['error' => 'Servicio de archivos no disponible'], 502);
    }
}
