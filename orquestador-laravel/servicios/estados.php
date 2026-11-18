<?php

// Servicio de estados de solicitudes · API local independiente
// Iniciar:  php -S 127.0.0.1:8002 servicios/estados.php
require __DIR__.'/comun.php';

if ($ruta === '/estados/101') {
    responder('estados', 200, ['solicitud_id' => 101, 'estado' => 'pendiente']);
} else {
    responder('estados', 404, ['error' => 'Solicitud no encontrada']);
}
