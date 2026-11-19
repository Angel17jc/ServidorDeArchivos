<?php

namespace App\Http\Controllers;

use App\Mensajeria\PublicadorMensajes;
use App\Models\Imagen;
use App\Models\Procesamiento;
use App\Services\GeneradorMiniaturas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Proceso elegido: generar la miniatura de la imagen de un producto guardada en el Nodo L5. */
class MiniaturaController extends Controller
{
    private const FORMATO_CID = '/^[A-Za-z0-9._-]{1,100}$/D';

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /** PRODUCTOR · POST /api/miniaturas → 202 Accepted: confirma la recepción, no que el trabajo terminó. */
    public function solicitar(Request $request, PublicadorMensajes $publicador): JsonResponse
    {
        $datos = $this->validar($request);
        $cid = $this->correlationId($request, $datos);
        $imagen = Imagen::findOrFail($datos['imagen_id']);

        $mensaje = [
            'message_id' => (string) Str::uuid(),         // identifica ESTE mensaje (único)
            'correlation_id' => $cid,                     // relaciona todos los registros de la operación
            'tipo' => 'archivo.miniatura_solicitada',
            'ocurrido_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'payload' => ['imagen_id' => $imagen->id, 'storage_key' => $imagen->storage_key, 'ancho' => $datos['ancho']],
        ];

        $procesamiento = Procesamiento::create([
            'message_id' => $mensaje['message_id'], 'correlation_id' => $cid, 'modo' => 'asincrono',
            'imagen_id' => $imagen->id, 'ancho' => $datos['ancho'], 'estado' => Procesamiento::PENDIENTE, 'publicado_en' => now(),
        ]);

        // Se registra ANTES de publicar: el consumidor puede tomar el mensaje en milisegundos
        // y su registro no debe aparecer antes que el del productor.
        $cola = config('mensajeria.miniaturas.cola');
        Log::channel('mensajeria')->info('Productor: publicando mensaje en la cola', [
            'correlation_id' => $cid, 'message_id' => $mensaje['message_id'], 'cola' => $cola, 'estado' => Procesamiento::PENDIENTE,
        ]);

        try {
            $publicador->publicar($cola, $mensaje);
        } catch (Throwable $e) {
            $procesamiento->update(['estado' => Procesamiento::ERROR, 'error' => 'Broker no disponible']);
            Log::channel('mensajeria')->error('Productor: no se pudo publicar', ['correlation_id' => $cid, 'message_id' => $mensaje['message_id'], 'motivo' => $e->getMessage()]);

            return response()->json(['error' => 'Servicio de mensajería no disponible; intente más tarde', 'correlation_id' => $cid], 503)
                ->header('X-Correlation-ID', $cid);
        }

        return response()->json([
            'mensaje' => 'Solicitud recibida. La miniatura se generará en segundo plano.',
            'message_id' => $mensaje['message_id'],
            'correlation_id' => $cid,
            'estado' => Procesamiento::PENDIENTE,
            'seguimiento' => '/api/procesamientos/'.$mensaje['message_id'],
        ], 202, ['X-Correlation-ID' => $cid], self::JSON);
    }

    /** Versión SÍNCRONA del mismo proceso: el cliente espera hasta que la miniatura existe. */
    public function sincrono(Request $request, GeneradorMiniaturas $generador): JsonResponse
    {
        $datos = $this->validar($request);
        $cid = $this->correlationId($request, $datos);
        $imagen = Imagen::findOrFail($datos['imagen_id']);

        $procesamiento = Procesamiento::create([
            'message_id' => (string) Str::uuid(), 'correlation_id' => $cid, 'modo' => 'sincrono', 'imagen_id' => $imagen->id,
            'ancho' => $datos['ancho'], 'estado' => Procesamiento::PROCESANDO, 'publicado_en' => now(), 'iniciado_en' => now(), 'intentos' => 1,
        ]);

        try {
            $resultado = $generador->generar($imagen->storage_key, $datos['ancho'], $cid);
        } catch (Throwable $e) {
            $procesamiento->update(['estado' => Procesamiento::ERROR, 'error' => $e->getMessage(), 'completado_en' => now()]);

            return response()->json(['error' => 'No se pudo generar la miniatura', 'correlation_id' => $cid], 502)->header('X-Correlation-ID', $cid);
        }

        $procesamiento->update(['estado' => Procesamiento::COMPLETADO, 'miniatura_key' => $resultado['storage_key'], 'completado_en' => now()]);
        Log::channel('mensajeria')->info('Síncrono: miniatura generada dentro de la petición', ['correlation_id' => $cid, 'message_id' => $procesamiento->message_id]);

        return response()->json($procesamiento->fresh('imagen')->aRespuesta(), 200, ['X-Correlation-ID' => $cid], self::JSON);
    }

    /** Seguimiento de un mensaje por su message_id. */
    public function estado(string $messageId): JsonResponse
    {
        $procesamiento = Procesamiento::with('imagen')->where('message_id', $messageId)->firstOrFail();

        return response()->json($procesamiento->aRespuesta(), 200, [], self::JSON);
    }

    /** Seguimiento de una operación completa por su correlation_id. */
    public function porCorrelacion(Request $request): JsonResponse
    {
        $request->validate(['correlation_id' => ['required', 'regex:'.self::FORMATO_CID]]);
        $lista = Procesamiento::with('imagen')->where('correlation_id', $request->query('correlation_id'))->orderBy('id')->get();

        return response()->json($lista->map->aRespuesta()->values(), 200, [], self::JSON);
    }

    private function validar(Request $request): array
    {
        $datos = $request->validate([
            'imagen_id' => ['required', 'integer', 'exists:imagenes,id'],
            'ancho' => ['sometimes', 'integer', 'min:50', 'max:800'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'regex:'.self::FORMATO_CID],
        ], [
            'imagen_id.required' => 'Indique la imagen a procesar (imagen_id).',
            'imagen_id.exists' => 'La imagen indicada no existe.',
            'ancho.min' => 'El ancho mínimo de la miniatura es 50 px.',
            'ancho.max' => 'El ancho máximo de la miniatura es 800 px.',
            'correlation_id.regex' => 'correlation_id solo admite de 1 a 100 letras, números, puntos, guiones o guiones bajos.',
        ]);
        $datos['ancho'] ??= 200;

        return $datos;
    }

    /** Conserva el correlation_id del cliente (cabecera X-Correlation-ID o campo del cuerpo) o genera uno nuevo. */
    private function correlationId(Request $request, array $datos): string
    {
        $cabecera = $request->header('X-Correlation-ID');
        if (is_string($cabecera) && preg_match(self::FORMATO_CID, $cabecera)) {
            return $cabecera;
        }

        return $datos['correlation_id'] ?? 'img-'.Str::lower(Str::random(8));
    }
}
