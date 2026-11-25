<?php

namespace App\Http\Controllers;

use App\Enrutamiento\Decision;
use App\Enrutamiento\EnrutadorContenido;
use App\Enrutamiento\FiltroMensajes;
use App\Mensajeria\PublicadorMensajes;
use App\Models\Evento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/**
 * PRODUCTOR · POST /api/eventos
 * Recibe los eventos de archivos del proyecto (fotos de productos, comprobantes de pago, bajas)
 * y aplica, en este orden:  validación → Message Filter → Content-Based Router → publicación en RabbitMQ.
 */
class EventoController extends Controller
{
    private const FORMATO_ID = '/^[A-Za-z0-9._-]{1,100}$/D';

    // Formato de las claves del Nodo L5: carpeta/uuid.extensión (impide rutas arbitrarias hacia L5)
    private const FORMATO_CLAVE = '/^(products|payments|users|messages|general)\/[0-9a-f-]{36}\.(jpg|png|webp|pdf)$/D';

    private const JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function recibir(Request $request, FiltroMensajes $filtro, EnrutadorContenido $enrutador, PublicadorMensajes $publicador): JsonResponse
    {
        $messageId = (string) Str::uuid();
        $cid = $this->correlationId($request);
        $log = Log::channel('mensajeria');
        $base = [
            'message_id' => $messageId, 'correlation_id' => $cid, 'recibido_en' => now(),
            'evento_id' => $this->texto($request->input('id'), 100), 'tipo' => $this->texto($request->input('tipo'), 60),
            'prioridad' => $this->texto($request->input('prioridad'), 10), 'estado_operacion' => $this->texto($request->input('estado'), 30),
        ];

        // 1. VALIDACIÓN del contrato: sin los campos indispensables no se filtra ni se enruta
        $validador = Validator::make($request->all(), $this->reglas(), $this->mensajes());
        if ($validador->fails()) {
            $motivo = implode(' ', $validador->errors()->all());
            Evento::create($base + ['resultado' => Evento::INVALIDO, 'regla' => 'V', 'motivo' => Str::limit($motivo, 290)]);
            $log->warning('Validación: mensaje rechazado', ['correlation_id' => $cid, 'message_id' => $messageId, 'motivo' => $motivo]);

            return $this->responder(422, [
                'resultado' => Evento::INVALIDO,
                'mensaje' => 'El mensaje no cumple el contrato: no se filtró, no se enrutó y no llegó a ningún consumidor.',
                'errores' => $validador->errors(),
            ], $messageId, $cid);
        }
        $evento = $validador->validated();
        $base['datos'] = $evento['datos'];

        // 2. MESSAGE FILTER: condiciones de negocio; si no se cumplen, el mensaje no se publica
        if ($rechazo = $filtro->evaluar($evento)) {
            Evento::create($base + ['resultado' => Evento::FILTRADO, 'regla' => $rechazo->regla, 'motivo' => $rechazo->motivo]);
            $log->notice('Filtro: mensaje descartado por regla de negocio', [
                'correlation_id' => $cid, 'message_id' => $messageId, 'evento_id' => $evento['id'], 'regla' => $rechazo->regla, 'motivo' => $rechazo->motivo]);

            return $this->responder(200, [
                'resultado' => Evento::FILTRADO,
                'mensaje' => 'El evento no cumple el filtro: no se publicó y no llegó a ningún consumidor.',
                'evento_id' => $evento['id'],
                'regla' => $rechazo->regla,
                'motivo' => $rechazo->motivo,
                'publicado' => false,
            ], $messageId, $cid);
        }

        // 3. CONTENT-BASED ROUTER: elige el destino según el tipo y el contenido
        $decision = $enrutador->decidir($evento);
        $prioridad = $enrutador->prioridadAmqp($evento['prioridad']);
        $destino = $decision->destino;
        $registro = Evento::create($base + [
            'resultado' => $decision->resultado, 'regla' => $decision->regla, 'destino' => $destino->value,
            'motivo' => $decision->motivo, 'procesamiento' => Evento::PENDIENTE,
        ]);

        $mensaje = [
            'message_id' => $messageId,
            'correlation_id' => $cid,
            'evento_id' => $evento['id'],
            'tipo' => $evento['tipo'],
            'prioridad' => $evento['prioridad'],
            'estado' => $evento['estado'],
            'datos' => $evento['datos'],
            'enrutamiento' => ['regla' => $decision->regla, 'destino' => $destino->value, 'motivo' => $decision->motivo],
            'publicado_en' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];

        // Se registra ANTES de publicar: el consumidor puede tomar el mensaje en milisegundos
        $log->info('Enrutador: '.($decision->resultado === Decision::ENRUTADO ? 'mensaje enrutado' : 'mensaje no enrutable'), [
            'correlation_id' => $cid, 'message_id' => $messageId, 'evento_id' => $evento['id'], 'tipo' => $evento['tipo'],
            'regla' => $decision->regla, 'routing_key' => $destino->routingKey(), 'cola' => $destino->cola(), 'prioridad' => $prioridad]);

        // 4. PUBLICACIÓN en el exchange con la routing key elegida
        try {
            $publicador->publicar($destino->routingKey(), $mensaje, $prioridad);
        } catch (Throwable $e) {
            // Fallo de transporte: distinto de un rechazo por regla de negocio
            $registro->update(['resultado' => Evento::FALLO_TRANSPORTE, 'procesamiento' => null, 'motivo' => 'RabbitMQ no disponible: el mensaje no se publicó']);
            $log->error('Productor: fallo de transporte al publicar', ['correlation_id' => $cid, 'message_id' => $messageId, 'motivo' => $e->getMessage()]);

            return $this->responder(503, [
                'resultado' => Evento::FALLO_TRANSPORTE,
                'mensaje' => 'El servicio de mensajería no está disponible; el evento no se publicó. Intente más tarde.',
            ], $messageId, $cid);
        }

        return $this->responder(202, [
            'resultado' => $decision->resultado,
            'mensaje' => $decision->resultado === Decision::ENRUTADO
                ? "Evento aceptado y enviado al {$destino->consumidor()}."
                : 'Evento aceptado pero no enrutable: quedó en la cola de revisión manual.',
            'evento_id' => $evento['id'],
            'regla' => $decision->regla,
            'motivo' => $decision->motivo,
            'destino' => $destino->value,
            'exchange' => config('mensajeria.enrutamiento.exchange'),
            'routing_key' => $destino->routingKey(),
            'cola' => $destino->cola(),
            'prioridad_amqp' => $prioridad,
        ], $messageId, $cid);
    }

    /** Seguimiento de un mensaje por su message_id. */
    public function mostrar(string $messageId): JsonResponse
    {
        return response()->json(Evento::where('message_id', $messageId)->firstOrFail()->aRespuesta(), 200, [], self::JSON);
    }

    /** Últimos mensajes, o los de una operación con ?correlation_id=… */
    public function listar(Request $request): JsonResponse
    {
        $request->validate(['correlation_id' => ['sometimes', 'regex:'.self::FORMATO_ID]]);
        $consulta = Evento::query()->orderByDesc('id');
        if ($request->filled('correlation_id')) {
            $consulta->where('correlation_id', $request->query('correlation_id'));
        }

        return response()->json($consulta->limit(20)->get()->map->aRespuesta()->values(), 200, [], self::JSON);
    }

    private function reglas(): array
    {
        return [
            'id' => ['required', 'string', 'regex:'.self::FORMATO_ID],
            // El tipo es obligatorio pero no se limita a una lista: un tipo desconocido lo resuelve el enrutador (R4)
            'tipo' => ['required', 'string', 'max:60'],
            'prioridad' => ['required', 'in:alta,normal,baja'],
            'estado' => ['required', 'string', 'max:30'],
            'datos' => ['required', 'array'],
            'datos.storage_key' => ['required', 'string', 'regex:'.self::FORMATO_CLAVE],
            'datos.tamano_bytes' => ['sometimes', 'integer', 'min:0'],
            'datos.referencia' => ['sometimes', 'string', 'max:60'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'regex:'.self::FORMATO_ID],
        ];
    }

    private function mensajes(): array
    {
        return [
            'id.required' => 'Falta el campo id.',
            'tipo.required' => 'Falta el campo tipo.',
            'prioridad.required' => 'Falta el campo prioridad.',
            'prioridad.in' => 'La prioridad debe ser alta, normal o baja.',
            'estado.required' => 'Falta el campo estado.',
            'datos.required' => 'Falta el objeto datos.',
            'datos.storage_key.required' => 'Falta datos.storage_key (clave del archivo en el Nodo L5).',
            'datos.storage_key.regex' => 'datos.storage_key debe tener el formato carpeta/uuid.extensión del Nodo L5.',
            'datos.tamano_bytes.integer' => 'datos.tamano_bytes debe ser un número entero.',
            'id.regex' => 'id solo admite de 1 a 100 letras, números, puntos, guiones o guiones bajos.',
            'correlation_id.regex' => 'correlation_id solo admite de 1 a 100 letras, números, puntos, guiones o guiones bajos.',
        ];
    }

    /** Conserva el correlation_id del cliente (cabecera X-Correlation-ID o campo del cuerpo) o genera uno nuevo. */
    private function correlationId(Request $request): string
    {
        foreach ([$request->header('X-Correlation-ID'), $request->input('correlation_id')] as $candidato) {
            if (is_string($candidato) && preg_match(self::FORMATO_ID, $candidato)) {
                return $candidato;
            }
        }

        return 'evt-'.Str::lower(Str::random(8));
    }

    /** Valor textual corto para la constancia (los campos pueden venir con cualquier forma si el mensaje es inválido). */
    private function texto(mixed $valor, int $maximo): ?string
    {
        return is_scalar($valor) ? Str::limit((string) $valor, $maximo, '') : null;
    }

    private function responder(int $estado, array $cuerpo, string $messageId, string $cid): JsonResponse
    {
        $cuerpo += ['message_id' => $messageId, 'correlation_id' => $cid, 'seguimiento' => '/api/eventos/'.$messageId];

        return response()->json($cuerpo, $estado, ['X-Correlation-ID' => $cid], self::JSON);
    }
}
