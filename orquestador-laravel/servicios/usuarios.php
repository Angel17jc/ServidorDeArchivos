<?php

// Servicio de usuarios · API local independiente
// Iniciar:  php -S 127.0.0.1:8001 servicios/usuarios.php
require __DIR__.'/comun.php';

if ($ruta === '/usuarios/7') {
    responder('usuarios', 200, ['id' => 7, 'nombre' => 'Ana']);
} else {
    responder('usuarios', 404, ['error' => 'Usuario no encontrado']);
}
