<?php

// Servicio de catálogo de la tienda (adaptación al proyecto) · API local independiente
// Iniciar:  php -S 127.0.0.1:8003 servicios/catalogo.php
// Los productos se leen de datos/catalogo.json (lo crea: php artisan demo:preparar-producto)
// o, si no existe, de datos/catalogo.ejemplo.json.
require __DIR__.'/comun.php';

$archivo = file_exists(__DIR__.'/datos/catalogo.json') ? 'catalogo.json' : 'catalogo.ejemplo.json';
$productos = json_decode(file_get_contents(__DIR__.'/datos/'.$archivo), true) ?: [];

if (preg_match('#^/productos/(\d+)$#', $ruta, $m) && isset($productos[$m[1]])) {
    responder('catalogo', 200, $productos[$m[1]]);
} else {
    responder('catalogo', 404, ['error' => 'Producto no encontrado']);
}
