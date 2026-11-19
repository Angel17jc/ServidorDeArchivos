<?php

// Usuarios de demostración para las pruebas de acceso. Correos ficticios;
// las contraseñas se leen SOLO de variables de entorno (DEMO_*_PASSWORD en .env).
return [
    'usuarios' => [
        ['nombre' => 'Ana López', 'email' => env('DEMO_CLIENTE_EMAIL', 'ana@tienda.test'), 'rol' => 'cliente', 'password' => env('DEMO_CLIENTE_PASSWORD')],
        ['nombre' => 'Luis Vera', 'email' => env('DEMO_CLIENTE2_EMAIL', 'luis@tienda.test'), 'rol' => 'cliente', 'password' => env('DEMO_CLIENTE2_PASSWORD')],
        ['nombre' => 'Admin Tienda', 'email' => env('DEMO_ADMIN_EMAIL', 'admin@tienda.test'), 'rol' => 'admin', 'password' => env('DEMO_ADMIN_PASSWORD')],
    ],
];
