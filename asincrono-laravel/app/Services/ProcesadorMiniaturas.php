<?php

namespace App\Services;

use App\Models\Procesamiento;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Lógica del consumidor para un mensaje "archivo.miniatura_solicitada".
 * Actualiza la tabla de estados y registra cada paso con el correlation_id.
 * Si termina sin excepción, el consumidor confirma el mensaje (ack); si lanza, lo rechaza.
 */
class ProcesadorMiniaturas
{
    public function __construct(private readonly GeneradorMiniaturas $generador) {}

    public function procesar(array $mensaje): Procesamiento
    {
        $messageId = $mensaje['message_id'] ?? null;
        $cid = $mensaje['correlation_id'] ?? '(sin cid)';
        $log = Log::channel('mensajeria');

        $procesamiento = is_string($messageId) ? Procesamiento::where('message_id', $messageId)->first() : null;
        if (! $procesamiento || ($mensaje['tipo'] ?? null) !== 'archivo.miniatura_solicitada') {
            throw new RuntimeException('Mensaje desconocido o con un tipo no soportado');
        }

        // Idempotencia: si RabbitMQ reentrega un mensaje ya procesado, no se repite el trabajo
        if ($procesamiento->estado === Procesamiento::COMPLETADO) {
            $log->info('Mensaje duplicado: ya estaba completado', ['correlation_id' => $cid, 'message_id' => $messageId]);

            return $procesamiento;
        }

        $procesamiento->update(['estado' => Procesamiento::PROCESANDO, 'iniciado_en' => now(), 'intentos' => $procesamiento->intentos + 1]);
        $log->info('Consumidor: procesando mensaje', ['correlation_id' => $cid, 'message_id' => $messageId, 'estado' => Procesamiento::PROCESANDO]);

        try {
            $resultado = $this->generador->generar($mensaje['payload']['storage_key'], (int) $mensaje['payload']['ancho'], $cid);
        } catch (Throwable $e) {
            $procesamiento->update(['estado' => Procesamiento::ERROR, 'error' => $e->getMessage(), 'completado_en' => now()]);
            $log->warning('Consumidor: procesamiento fallido', ['correlation_id' => $cid, 'message_id' => $messageId, 'estado' => Procesamiento::ERROR, 'motivo' => $e->getMessage()]);

            throw $e;
        }

        $procesamiento->update(['estado' => Procesamiento::COMPLETADO, 'miniatura_key' => $resultado['storage_key'], 'completado_en' => now()]);
        $log->info('Consumidor: miniatura generada', ['correlation_id' => $cid, 'message_id' => $messageId, 'estado' => Procesamiento::COMPLETADO,
            'miniatura' => $resultado['storage_key'], 'dimensiones' => "{$resultado['ancho']}x{$resultado['alto']}"]);

        return $procesamiento;
    }
}
