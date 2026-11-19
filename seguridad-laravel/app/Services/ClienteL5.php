<?php

namespace App\Services;

use App\Exceptions\L5NoDisponible;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

/**
 * Comunicación servicio a servicio con el Nodo L5 (servidor de archivos).
 * Laravel se identifica ante L5 con la clave de servicio de la variable de entorno
 * L5_FILES_API_KEY (cabecera x-api-key); la clave nunca está escrita en el código.
 */
class ClienteL5
{
    public function __construct(
        private readonly string $url,
        private readonly string $clave,
        private readonly int $timeout = 5,
    ) {}

    /** Sube el archivo a L5 y devuelve su contrato: storage_key, mime_type, size. */
    public function subir(UploadedFile $archivo): array
    {
        $respuesta = $this->enviar(fn (PendingRequest $http) => $http
            ->attach('file', $archivo->getContent(), $archivo->getClientOriginalName(), ['Content-Type' => $archivo->getMimeType()])
            ->post($this->url.'/upload'));

        if ($respuesta->status() !== 201 || ! is_string($respuesta->json('storage_key'))) {
            throw new L5NoDisponible("L5 rechazó la carga (HTTP {$respuesta->status()})");
        }

        return $respuesta->json();
    }

    /** Elimina el archivo en L5; si ya no existe (404) se considera eliminado. */
    public function eliminar(string $storageKey): void
    {
        $respuesta = $this->enviar(fn (PendingRequest $http) => $http->delete($this->url.'/files/'.rawurlencode($storageKey)));

        if (! $respuesta->successful() && $respuesta->status() !== 404) {
            throw new L5NoDisponible("L5 no eliminó el archivo (HTTP {$respuesta->status()})");
        }
    }

    private function enviar(callable $peticion)
    {
        try {
            return $peticion(Http::withHeaders(['x-api-key' => $this->clave])->acceptJson()->timeout($this->timeout));
        } catch (ConnectionException) {
            throw new L5NoDisponible('L5 no responde');
        }
    }
}
