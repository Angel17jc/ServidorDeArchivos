<?php

// Direcciones de las dependencias que coordina el orquestador.
// El código lee config(), no env(), para que funcione también con la configuración cacheada.
return [
    // Servicios de prueba de la actividad (APIs locales independientes)
    'usuarios_url' => env('USERS_URL', 'http://127.0.0.1:8001'),
    'estados_url' => env('STATUS_URL', 'http://127.0.0.1:8002'),

    // Adaptación al proyecto: catálogo de la tienda y Nodo L5 (servidor de archivos)
    'catalogo_url' => env('CATALOG_URL', 'http://127.0.0.1:8003'),
    'archivos_url' => env('FILES_URL', 'http://127.0.0.1:8080'),
    'archivos_api_key' => env('FILES_API_KEY'),

    // Segundos de espera máxima para conectar y para responder, en cada consulta
    'timeout' => (int) env('ORQUESTACION_TIMEOUT', 3),
];
