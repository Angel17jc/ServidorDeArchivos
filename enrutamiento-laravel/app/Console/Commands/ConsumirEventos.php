<?php

namespace App\Console\Commands;

use App\Consumidores\ConsumidorComprobantes;
use App\Consumidores\ConsumidorEvento;
use App\Consumidores\ConsumidorLimpieza;
use App\Consumidores\ConsumidorMiniaturas;
use App\Enrutamiento\Destino;
use App\Mensajeria\ConexionRabbit;
use App\Models\Evento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

/**
 * CONSUMIDOR independiente de una de las tres colas enrutadas. Se ejecuta uno por ventana:
 *   php artisan eventos:consumir miniaturas | comprobantes | limpieza
 *  - ack solo después de procesar correctamente;
 *  - nack sin reencolar si falla: RabbitMQ lo mueve a eventos.errores;
 *  - si recibe un mensaje enrutado a otro destino, lo rechaza (cada mensaje debe llegar solo a su consumidor).
 */
class ConsumirEventos extends Command
{
    protected $signature = 'eventos:consumir {destino : miniaturas | comprobantes | limpieza} {--max=0 : Detenerse después de N mensajes (0 = sin límite)}';

    protected $description = 'Consumidor de una cola del enrutamiento de eventos (RabbitMQ)';

    public function handle(): int
    {
        $destino = Destino::tryFrom((string) $this->argument('destino'));
        if (! in_array($destino, Destino::consumibles(), true)) {
            $this->error('Destino no válido. Use: miniaturas, comprobantes o limpieza.');

            return self::INVALID;
        }

        $consumidor = $this->consumidor($destino);
        $maximo = (int) $this->option('max');
        $procesados = 0;
        $errores = config('mensajeria.enrutamiento.cola_errores');

        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararTopologia($canal);
        $canal->basic_qos(0, 1, false); // un mensaje a la vez: no se toma otro hasta confirmar el actual

        $this->info("{$destino->consumidor()} · cola {$destino->cola()} · routing key «{$destino->routingKey()}» · Ctrl+C para detenerlo");

        $canal->basic_consume($destino->cola(), 'consumidor-'.$destino->value, false, false, false, false,
            function (AMQPMessage $amqp) use ($destino, $consumidor, $errores, $maximo, &$procesados) {
                $this->atender($amqp, $destino, $consumidor, $errores);
                if ($maximo > 0 && ++$procesados >= $maximo) {
                    $amqp->getChannel()->basic_cancel($amqp->getConsumerTag());
                }
            });

        while ($canal->is_consuming()) {
            $canal->wait();
        }

        $canal->close();
        $conexion->close();

        return self::SUCCESS;
    }

    private function atender(AMQPMessage $amqp, Destino $destino, ConsumidorEvento $consumidor, string $errores): void
    {
        $log = Log::channel('mensajeria');
        $mensaje = json_decode($amqp->getBody(), true);
        $cid = $mensaje['correlation_id'] ?? '(sin cid)';
        $messageId = $mensaje['message_id'] ?? '?';
        $eventoId = $mensaje['evento_id'] ?? '?';
        $registro = is_string($messageId) ? Evento::where('message_id', $messageId)->first() : null;

        $this->line(sprintf('[%s] RECIBIDO  %s  tipo=%s  prioridad=%s  regla=%s  correlation_id=%s', $this->hora(), $eventoId,
            $mensaje['tipo'] ?? '?', $mensaje['prioridad'] ?? '?', $mensaje['enrutamiento']['regla'] ?? '?', $cid));
        $log->info("Consumidor {$destino->value}: mensaje recibido", ['correlation_id' => $cid, 'message_id' => $messageId, 'evento_id' => $eventoId]);

        try {
            if (($mensaje['enrutamiento']['destino'] ?? null) !== $destino->value) {
                throw new RuntimeException('El mensaje fue enrutado a otro destino: este consumidor no lo procesa');
            }

            // Idempotencia: si RabbitMQ reentrega un mensaje ya procesado, no se repite el trabajo
            if ($registro?->procesamiento === Evento::PROCESADO) {
                $amqp->ack();
                $this->comment(sprintf('[%s] ACK       %s  duplicado: ya estaba procesado', $this->hora(), $eventoId));

                return;
            }

            $resultado = $consumidor->procesar($mensaje);
            $registro?->update([
                'procesamiento' => Evento::PROCESADO, 'consumidor' => $destino->consumidor(), 'detalle' => $resultado['detalle'],
                'archivo_generado' => $resultado['archivo_generado'] ?? null, 'procesado_en' => now(),
            ]);
            $amqp->ack(); // confirmar solo después de terminar bien
            $this->info(sprintf('[%s] ACK       %s', $this->hora(), $eventoId));
            $this->line(str_repeat(' ', 15).$resultado['detalle']);
            $log->info("Consumidor {$destino->value}: mensaje procesado (ack)", ['correlation_id' => $cid, 'message_id' => $messageId, 'detalle' => $resultado['detalle']]);
        } catch (Throwable $e) {
            $registro?->update(['procesamiento' => Evento::ERROR, 'consumidor' => $destino->consumidor(), 'detalle' => mb_substr($e->getMessage(), 0, 290), 'procesado_en' => now()]);
            $amqp->nack(false); // sin reencolar: RabbitMQ lo mueve a la cola de errores
            $this->error(sprintf('[%s] NACK      %s  → %s', $this->hora(), $eventoId, $errores));
            $this->line(str_repeat(' ', 15).'motivo: '.$e->getMessage());
            $log->warning("Consumidor {$destino->value}: procesamiento fallido (nack → {$errores})", ['correlation_id' => $cid, 'message_id' => $messageId, 'motivo' => $e->getMessage()]);
        }
    }

    private function consumidor(Destino $destino): ConsumidorEvento
    {
        return app(match ($destino) {
            Destino::Miniaturas => ConsumidorMiniaturas::class,
            Destino::Comprobantes => ConsumidorComprobantes::class,
            Destino::Limpieza => ConsumidorLimpieza::class,
        });
    }

    private function hora(): string
    {
        return now()->format('H:i:s.v');
    }
}
