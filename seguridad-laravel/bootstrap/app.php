<?php

use App\Http\Middleware\RegistrarAccesoDenegado;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Auditoría de accesos rechazados (401/403). Es global para envolver también a auth:sanctum:
        // dentro del grupo api, Laravel ejecuta la autenticación antes y los 401 no llegarían a registrarse.
        $middleware->append(RegistrarAccesoDenegado::class);

        // En el despliegue, NGINX (L1, 192.168.10.11) termina TLS y reenvía con X-Forwarded-Proto: https
        $middleware->trustProxies(at: ['192.168.10.11']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Respuestas de seguridad en JSON, con mensajes fijos y sin trazas ni detalles internos
        $exceptions->render(fn (AuthenticationException $e, Request $request) => response()->json(
            ['error' => 'No autenticado', 'detalle' => 'Envíe un token Bearer válido en la cabecera Authorization'], 401));

        $exceptions->render(fn (AuthorizationException|AccessDeniedHttpException $e, Request $request) => response()->json(
            ['error' => 'Acceso denegado', 'detalle' => 'Su rol no tiene permiso para esta operación sobre el recurso'], 403));

        $exceptions->render(fn (ThrottleRequestsException $e, Request $request) => response()->json(
            ['error' => 'Demasiados intentos', 'detalle' => 'Espere un minuto antes de volver a intentar'], 429, $e->getHeaders()));

        $exceptions->render(fn (NotFoundHttpException $e, Request $request) => $request->is('api/*')
            ? response()->json(['error' => 'Recurso no encontrado'], 404)
            : null);
    })->create();
