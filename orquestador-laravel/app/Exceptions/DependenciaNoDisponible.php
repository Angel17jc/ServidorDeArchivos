<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Una dependencia no respondió, respondió con error o envió datos inválidos.
 * Se registra para diagnóstico y el cliente recibe un 502 controlado, sin trazas.
 */
class DependenciaNoDisponible extends RuntimeException
{
    public function __construct(
        public readonly string $servicio,
        public readonly string $motivo,
        ?Throwable $previa = null,
    ) {
        parent::__construct("{$servicio}: {$motivo}", 0, $previa);
    }

    // Registro en storage/logs/laravel.log; el cid se agrega desde el middleware CorrelationId.
    public function report(): void
    {
        Log::warning('Falla de orquestación', [
            'servicio' => $this->servicio,
            'motivo' => $this->motivo,
        ]);
    }

    // Respuesta al cliente: mensaje fijo y la correlación, nunca detalles internos.
    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'Dependencia no disponible',
            'correlation_id' => $request->attributes->get('correlation_id'),
        ], 502);
    }
}
