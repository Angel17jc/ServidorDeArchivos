<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El trabajo pesado del proceso: descarga la imagen del Nodo L5, genera la miniatura y la sube a L5.
 * Lo usan por igual el consumidor (asíncrono) y el endpoint síncrono, para comparar en igualdad de condiciones.
 */
class GeneradorMiniaturas
{
    /** @return array{storage_key: string, ancho: int, alto: int, bytes: int} */
    public function generar(string $storageKey, int $ancho, string $correlationId): array
    {
        $l5 = config('mensajeria.l5');
        $cabeceras = ['X-Correlation-ID' => $correlationId];

        // 1. Descargar la imagen original desde L5 (lectura pública)
        $original = Http::withHeaders($cabeceras)->timeout(10)->get($l5['url'].'/files/'.rawurlencode($storageKey));
        if (! $original->successful()) {
            throw new RuntimeException("L5 no entregó la imagen (HTTP {$original->status()})");
        }

        // 2. Generar la miniatura conservando la proporción
        $imagen = @imagecreatefromstring($original->body());
        if ($imagen === false) {
            throw new RuntimeException('El archivo descargado no es una imagen válida');
        }
        $miniatura = imagescale($imagen, $ancho);
        ob_start();
        imagejpeg($miniatura, null, 85);
        $jpeg = (string) ob_get_clean();
        [$w, $h] = [imagesx($miniatura), imagesy($miniatura)];

        // Trabajo adicional simulado (optimización de varias versiones de la imagen); configurable en .env
        usleep(max(0, (int) config('mensajeria.miniaturas.retraso_ms')) * 1000);

        // 3. Subir la miniatura a L5 con la credencial de servicio
        $subida = Http::withHeaders($cabeceras + ['x-api-key' => (string) $l5['key']])
            ->timeout(10)
            ->attach('file', $jpeg, "miniatura-{$ancho}.jpg", ['Content-Type' => 'image/jpeg'])
            ->post($l5['url'].'/upload');
        if ($subida->status() !== 201) {
            throw new RuntimeException("L5 rechazó la miniatura (HTTP {$subida->status()})");
        }

        return ['storage_key' => $subida->json('storage_key'), 'ancho' => $w, 'alto' => $h, 'bytes' => strlen($jpeg)];
    }
}
