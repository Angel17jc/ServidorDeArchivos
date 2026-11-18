<?php

namespace App\Http\Controllers;

use App\Exceptions\DependenciaNoDisponible;
use App\Services\ClienteDependencias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/resumen-solicitud/{id}
 * Orquesta dos servicios independientes (usuarios y estados) y compone una sola respuesta.
 */
class ResumenSolicitudController extends Controller
{
    // La actividad fija el usuario 7 (Ana); un sistema real lo obtendría de la solicitud.
    private const USUARIO_ID = 7;

    public function __invoke(Request $request, string $id, ClienteDependencias $dependencias): JsonResponse
    {
        $cid = $request->attributes->get('correlation_id');

        // 1. ¿Quién es el usuario?  2. ¿En qué estado está la solicitud?
        // Consultas secuenciales; cada una con su propio límite de 3 segundos.
        $usuario = $dependencias->obtenerJson('usuarios', config('orquestacion.usuarios_url').'/usuarios/'.self::USUARIO_ID, $cid);
        $estado = $dependencias->obtenerJson('estados', config('orquestacion.estados_url').'/estados/'.$id, $cid);

        // 3. Un HTTP 200 no garantiza datos correctos: se valida el contenido de cada servicio.
        if (($usuario['id'] ?? null) !== self::USUARIO_ID || ! is_string($usuario['nombre'] ?? null)) {
            throw new DependenciaNoDisponible('usuarios', 'contenido inválido');
        }
        if ((string) ($estado['solicitud_id'] ?? '') !== $id || ! is_string($estado['estado'] ?? null)) {
            throw new DependenciaNoDisponible('estados', 'contenido inválido');
        }

        // 4. Composición: una respuesta unificada con la correlación en el cuerpo y en la cabecera.
        return response()->json([
            'solicitud_id' => (int) $id,
            'usuario' => ['id' => $usuario['id'], 'nombre' => $usuario['nombre']],
            'estado' => ['solicitud_id' => (int) $estado['solicitud_id'], 'estado' => $estado['estado']],
            'correlation_id' => $cid,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
