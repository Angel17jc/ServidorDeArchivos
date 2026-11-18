<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Genera o propaga X-Correlation-ID: el identificador que une todas las llamadas
 * de una misma operación (cliente → orquestador → servicios → respuesta).
 */
class CorrelationId
{
    public const CABECERA = 'X-Correlation-ID';

    public function handle(Request $request, Closure $next): Response
    {
        // Se acepta el del cliente si tiene de 1 a 100 letras, números, puntos, guiones o guiones bajos.
        // Si no llega o no es válido, se genera un UUID.
        $recibido = $request->header(self::CABECERA);
        $cid = is_string($recibido) && preg_match('/^[A-Za-z0-9._-]{1,100}$/D', $recibido)
            ? $recibido
            : (string) Str::uuid();

        $request->attributes->set('correlation_id', $cid);
        Log::withContext(['cid' => $cid]); // todo registro de esta petición lleva el cid

        $respuesta = $next($request);
        $respuesta->headers->set(self::CABECERA, $cid); // también en las respuestas 502

        return $respuesta;
    }
}
