<?php

// Conexión con RabbitMQ, topología del enrutamiento y parámetros de los consumidores.
// Los valores salen de .env; las credenciales nunca se escriben en el código.
return [
    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER'),
        'password' => env('RABBITMQ_PASSWORD'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],

    'enrutamiento' => [
        // Exchange directo: entrega cada mensaje a la cola enlazada con la routing key que eligió el enrutador
        'exchange' => env('ENRUTAMIENTO_EXCHANGE', 'archivos.eventos'),
        // Cola de mensajes que un consumidor no pudo procesar (fallo técnico, no regla de negocio)
        'cola_errores' => env('ENRUTAMIENTO_COLA_ERRORES', 'eventos.errores'),
        // Message Filter: tamaño máximo aceptado (el mismo límite de 10 MB que aplica el Nodo L5)
        'tamano_maximo_bytes' => (int) env('FILTRO_TAMANO_MAXIMO_BYTES', 10 * 1024 * 1024),
    ],

    'miniaturas' => [
        'ancho' => (int) env('MINIATURAS_ANCHO', 200),
        // Trabajo adicional simulado en milisegundos (0 = solo el trabajo real)
        'retraso_ms' => (int) env('MINIATURAS_RETRASO_MS', 0),
    ],

    // Nodo L5: los consumidores descargan, suben y eliminan archivos aquí
    'l5' => [
        'url' => env('L5_FILES_URL', 'http://127.0.0.1:8080'),
        'key' => env('L5_FILES_API_KEY'),
    ],
];
