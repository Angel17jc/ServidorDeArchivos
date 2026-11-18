<?php

namespace App\Services;

use App\Exceptions\DependenciaNoDisponible;
use App\Http\Middleware\CorrelationId;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Llamadas HTTP a las dependencias, todas con las mismas reglas:
 * tiempo máximo, X-Correlation-ID propagado y código HTTP validado.
 */
class ClienteDependencias
{
    public function __construct(private readonly int $timeout = 3) {}

    /** GET que debe devolver 2xx y un objeto JSON. */
    public function obtenerJson(string $servicio, string $url, string $cid): array
    {
        $datos = $this->enviar($servicio, $cid, fn (PendingRequest $http) => $http->acceptJson()->get($url))->json();

        if (! is_array($datos)) {
            throw new DependenciaNoDisponible($servicio, 'la respuesta no es un objeto JSON');
        }

        return $datos;
    }

    /** HEAD para comprobar un recurso sin descargarlo (lo usa la consulta al Nodo L5). */
    public function obtenerCabeceras(string $servicio, string $url, string $cid): Response
    {
        return $this->enviar($servicio, $cid, fn (PendingRequest $http) => $http->head($url));
    }

    private function enviar(string $servicio, string $cid, callable $peticion): Response
    {
        try {
            // Equivale a Http::timeout(3)->get(...): conectar y responder en 3 s como máximo.
            $respuesta = $peticion(
                Http::withHeaders([CorrelationId::CABECERA => $cid])
                    ->connectTimeout($this->timeout)
                    ->timeout($this->timeout)
            );
        } catch (ConnectionException $e) {
            // Conexión rechazada, servicio apagado o tiempo agotado
            throw new DependenciaNoDisponible($servicio, 'sin conexión o tiempo agotado', $e);
        }

        // successful() acepta 200–299; un 404 o 503 de la dependencia es un fallo de orquestación
        if (! $respuesta->successful()) {
            throw new DependenciaNoDisponible($servicio, "respondió HTTP {$respuesta->status()}");
        }

        return $respuesta;
    }
}
