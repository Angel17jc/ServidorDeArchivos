<?php

// Utilidades compartidas por los servicios de prueba. Cada servicio es una API independiente:
// se ejecuta en su propio proceso y puerto (php -S), separado del orquestador Laravel.

header('Content-Type: application/json; charset=utf-8');

// Correlación: si llega un X-Correlation-ID válido, se devuelve y se registra en esta terminal.
$cid = $_SERVER['HTTP_X_CORRELATION_ID'] ?? '';
if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $cid)) {
    $cid = '(sin cid)';
} else {
    header('X-Correlation-ID: '.$cid);
}

// Lentitud simulada para probar el tiempo máximo del orquestador, sin editar el código:
//   $env:RETRASO_SEGUNDOS=5; php -S 127.0.0.1:8002 servicios/estados.php
$retraso = (int) getenv('RETRASO_SEGUNDOS');
if ($retraso > 0) {
    sleep($retraso);
}

$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function responder(string $servicio, int $codigo, array $cuerpo): void
{
    global $cid, $ruta;
    http_response_code($codigo);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE);
    error_log("[{$servicio}] cid={$cid} GET {$ruta} -> {$codigo}");
}
