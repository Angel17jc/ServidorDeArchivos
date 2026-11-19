<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbit;
use App\Services\ProcesadorMiniaturas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

/**
 * CONSUMIDOR independiente: lee la cola archivos.miniaturas y procesa cada mensaje.
 *  - ack  solo después de procesar correctamente;
 *  - nack sin reencolar si falla: RabbitMQ lo mueve a archivos.miniaturas.errores;
 *  - si el consumidor se detiene antes del ack, RabbitMQ vuelve a entregar el mensaje.
 */
class ConsumirMiniaturas extends Command
{
    protected $signature = 'miniaturas:consumir {--max=0 : Detenerse después de procesar N mensajes (0 = sin límite)}';

    protected $description = 'Consumidor de la cola de miniaturas (RabbitMQ)';

    public function handle(ProcesadorMiniaturas $procesador): int
    {
        $cola = config('mensajeria.miniaturas.cola');
        $errores = config('mensajeria.miniaturas.cola_errores');
        $maximo = (int) $this->option('max');
        $procesados = 0;
        $log = Log::channel('mensajeria');

        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararColas($canal);
        $canal->basic_qos(0, 1, false); // un mensaje a la vez: no se toma otro hasta confirmar el actual

        $this->info("Consumidor escuchando la cola «{$cola}». Presione Ctrl+C para detenerlo.");

        $canal->basic_consume($cola, 'consumidor-miniaturas', false, false, false, false,
            function (AMQPMessage $mensaje) use ($procesador, $log, $errores, $maximo, &$procesados) {
                $datos = json_decode($mensaje->getBody(), true);
                $cid = is_array($datos) ? ($datos['correlation_id'] ?? '(sin cid)') : '(sin cid)';
                $id = is_array($datos) ? ($datos['message_id'] ?? '?') : '?';

                $this->line(sprintf('[%s] RECIBIDO   correlation_id=%s  message_id=%s', now()->format('H:i:s.v'), $cid, $id));
                $log->info('Consumidor: mensaje recibido', ['correlation_id' => $cid, 'message_id' => $id]);

                try {
                    if (! is_array($datos)) {
                        throw new RuntimeException('El cuerpo del mensaje no es JSON');
                    }
                    $resultado = $procesador->procesar($datos);
                    $mensaje->ack(); // confirmar solo después de terminar bien
                    $this->info(sprintf('[%s] ACK        correlation_id=%s  estado=%s  miniatura=%s',
                        now()->format('H:i:s.v'), $cid, $resultado->estado, $resultado->miniatura_key));
                    $log->info('Consumidor: mensaje confirmado (ack)', ['correlation_id' => $cid, 'message_id' => $id]);
                } catch (Throwable $e) {
                    $mensaje->nack(false); // sin reencolar: RabbitMQ lo mueve a la cola de errores
                    $this->error(sprintf('[%s] NACK       correlation_id=%s  → %s  motivo: %s',
                        now()->format('H:i:s.v'), $cid, $errores, $e->getMessage()));
                    $log->warning('Consumidor: mensaje rechazado (nack) y enviado a la cola de errores',
                        ['correlation_id' => $cid, 'message_id' => $id, 'cola' => $errores]);
                }

                if ($maximo > 0 && ++$procesados >= $maximo) {
                    $mensaje->getChannel()->basic_cancel($mensaje->getConsumerTag());
                }
            });

        while ($canal->is_consuming()) {
            $canal->wait();
        }

        $canal->close();
        $conexion->close();

        return self::SUCCESS;
    }
}
