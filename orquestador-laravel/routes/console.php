<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prepara la adaptación al proyecto: sube la foto de ejemplo al Nodo L5 y registra
// en el catálogo la clave que L5 devuelve (servicios/datos/catalogo.json).
Artisan::command('demo:preparar-producto', function () {
    $foto = resource_path('demo/camisa-azul.jpg');
    $respuesta = Http::withHeaders(['x-api-key' => (string) config('orquestacion.archivos_api_key')])
        ->timeout(10)
        ->attach('file', file_get_contents($foto), 'camisa-azul.jpg', ['Content-Type' => 'image/jpeg'])
        ->post(config('orquestacion.archivos_url').'/upload');

    if (! $respuesta->successful() || ! is_string($respuesta->json('storage_key'))) {
        $this->error('L5 no aceptó la imagen: HTTP '.$respuesta->status().' '.$respuesta->body());

        return 1;
    }

    $catalogo = [15 => ['id' => 15, 'nombre' => 'Camisa azul', 'precio' => 19.99, 'imagen' => $respuesta->json('storage_key')]];
    file_put_contents(base_path('servicios/datos/catalogo.json'), json_encode($catalogo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $this->info('Imagen guardada en L5 con la clave '.$respuesta->json('storage_key'));
    $this->info('Producto 15 actualizado en servicios/datos/catalogo.json');
})->purpose('Sube la imagen de ejemplo al Nodo L5 y la asocia al producto 15 del catálogo');
