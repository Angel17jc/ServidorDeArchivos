<?php

namespace App\Http\Middleware;

use App\Support\Enmascarar;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Registra los intentos de acceso rechazados (401 y 403) para auditoría.
 * El token se guarda enmascarado: nunca se escribe completo en storage/logs/laravel.log.
 */
class RegistrarAccesoDenegado
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if ($request->is('api/*') && in_array($respuesta->getStatusCode(), [401, 403], true)) {
            Log::warning('Acceso denegado', [
                'estado' => $respuesta->getStatusCode(),
                'metodo' => $request->method(),
                'ruta' => $request->path(),
                'usuario_id' => $request->user()?->id,
                'token' => Enmascarar::token($request->bearerToken()),
                'ip' => $request->ip(),
            ]);
        }

        return $respuesta;
    }
}
