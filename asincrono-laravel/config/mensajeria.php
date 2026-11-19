<?php

// Conexión con el broker RabbitMQ y parámetros del proceso de miniaturas.
// Los valores salen de .env; las credenciales nunca se escriben en el código.
return [
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER'),
        'password' => env('RABBITMQ_PASSWORD'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    'miniaturas' => [
        'cola' => env('MINIATURAS_COLA', 'archivos.miniaturas'),
        'cola_errores' => env('MINIATURAS_COLA_ERRORES', 'archivos.miniaturas.errores'),
        'retraso_ms' => (int) env('MINIATURAS_RETRASO_MS', 2500),
    ],

    // Nodo L5: de aquí se descarga la imagen original y allí se sube la miniatura
    'l5' => [
        'url' => env('L5_FILES_URL', 'http://127.0.0.1:8080'),
        'key' => env('L5_FILES_API_KEY'),
    ],
];
