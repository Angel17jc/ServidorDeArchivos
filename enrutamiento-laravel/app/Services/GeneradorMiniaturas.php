<?php

namespace App\Services;

use RuntimeException;

/**
 * Reutilizado de la práctica 3.1: descarga la imagen del Nodo L5, genera la miniatura con GD
 * y la sube a L5 en la carpeta products.
 */
class GeneradorMiniaturas
{
    public function __construct(private readonly ClienteL5 $l5) {}

    /** @return array{storage_key: string, ancho: int, alto: int, bytes: int} */
    public function generar(string $storageKey, int $ancho, string $correlationId): array
    {
        // 1. Descargar la imagen original
        $imagen = @imagecreatefromstring($this->l5->descargar($storageKey, $correlationId));
        if ($imagen === false) {
            throw new RuntimeException('El archivo descargado no es una imagen válida');
        }

        // 2. Generar la miniatura conservando la proporción
        $miniatura = imagescale($imagen, $ancho);
        ob_start();
        imagejpeg($miniatura, null, 85);
        $jpeg = (string) ob_get_clean();

        usleep(max(0, (int) config('mensajeria.miniaturas.retraso_ms')) * 1000);

        // 3. Subir la miniatura a L5
        $subida = $this->l5->subir($jpeg, "miniatura-{$ancho}.jpg", 'image/jpeg', 'products', $correlationId);

        return ['storage_key' => $subida['storage_key'], 'ancho' => imagesx($miniatura), 'alto' => imagesy($miniatura), 'bytes' => strlen($jpeg)];
    }
}
