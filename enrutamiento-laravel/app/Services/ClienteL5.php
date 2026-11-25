<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Acceso al Nodo L5 (servidor de archivos). La lectura es pública; subir y eliminar
 * requieren la credencial de servicio (cabecera x-api-key), que sale de .env.
 * Cada petición lleva el X-Correlation-ID del mensaje para seguirla en los registros de L5.
 */
class ClienteL5
{
    public function descargar(string $clave, string $correlationId): string
    {
        $r = Http::withHeaders(['X-Correlation-ID' => $correlationId])->timeout(10)->get($this->url($clave));
        if (! $r->successful()) {
            throw new RuntimeException("L5 no entregó el archivo {$clave} (HTTP {$r->status()})");
        }

        return $r->body();
    }

    /** @return array{storage_key: string} */
    public function subir(string $contenido, string $nombre, string $mime, string $carpeta, string $correlationId): array
    {
        $r = Http::withHeaders($this->cabeceras($correlationId))->timeout(10)
            ->attach('file', $contenido, $nombre, ['Content-Type' => $mime])
            ->post(config('mensajeria.l5.url').'/files?folder='.$carpeta);
        if ($r->status() !== 201) {
            throw new RuntimeException("L5 rechazó la subida de {$nombre} (HTTP {$r->status()})");
        }

        return $r->json();
    }

    /** true si se eliminó; false si el archivo ya no existía (la limpieza es idempotente). */
    public function eliminar(string $clave, string $correlationId): bool
    {
        $r = Http::withHeaders($this->cabeceras($correlationId))->timeout(10)->delete($this->url($clave));

        return match ($r->status()) {
            204 => true,
            404 => false,
            default => throw new RuntimeException("L5 no eliminó el archivo {$clave} (HTTP {$r->status()})"),
        };
    }

    /** La clave ya fue validada con el formato de L5 (carpeta/uuid.ext), así que no puede salir de /files. */
    private function url(string $clave): string
    {
        return config('mensajeria.l5.url').'/files/'.$clave;
    }

    private function cabeceras(string $correlationId): array
    {
        return ['X-Correlation-ID' => $correlationId, 'x-api-key' => (string) config('mensajeria.l5.key')];
    }
}
