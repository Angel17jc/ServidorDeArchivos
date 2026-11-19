<?php

namespace App\Console\Commands;

use App\Models\Imagen;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Sube al Nodo L5 tres fotos de producto ficticias y las registra en la tabla imagenes. */
class PrepararImagenesDemo extends Command
{
    protected $signature = 'demo:preparar-imagenes';

    protected $description = 'Sube tres imágenes de producto de ejemplo al Nodo L5';

    private const PRODUCTOS = [
        ['Camisa azul', [29, 78, 216]],
        ['Zapatos café', [120, 72, 30]],
        ['Gorra roja', [190, 30, 45]],
    ];

    public function handle(): int
    {
        $l5 = config('mensajeria.l5');
        $filas = [];

        foreach (self::PRODUCTOS as [$producto, $color]) {
            $jpeg = $this->foto($producto, $color);
            $respuesta = Http::withHeaders(['x-api-key' => (string) $l5['key']])->timeout(10)
                ->attach('file', $jpeg, str_replace(' ', '-', strtolower($producto)).'.jpg', ['Content-Type' => 'image/jpeg'])
                ->post($l5['url'].'/upload');

            if ($respuesta->status() !== 201) {
                $this->error("L5 no aceptó la imagen de {$producto} (HTTP {$respuesta->status()}). ¿Está activo el Nodo L5?");

                return self::FAILURE;
            }

            $imagen = Imagen::updateOrCreate(['producto' => $producto], [
                'storage_key' => $respuesta->json('storage_key'), 'mime_type' => 'image/jpeg', 'size' => strlen($jpeg),
            ]);
            $filas[] = [$imagen->id, $producto, $imagen->storage_key, number_format(strlen($jpeg)).' B'];
        }

        $this->table(['imagen_id', 'Producto', 'storage_key en L5', 'Tamaño'], $filas);

        return self::SUCCESS;
    }

    /** Foto ficticia de 1200x900 generada con GD. */
    private function foto(string $producto, array $rgb): string
    {
        $img = imagecreatetruecolor(1200, 900);
        imagefill($img, 0, 0, imagecolorallocate($img, 241, 245, 249));
        $color = imagecolorallocate($img, ...$rgb);
        imagefilledrectangle($img, 330, 220, 870, 760, $color);
        imagefilledellipse($img, 600, 220, 300, 160, imagecolorallocate($img, 241, 245, 249));
        imagestring($img, 5, 520, 800, $producto, imagecolorallocate($img, 31, 41, 55));
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }
}
