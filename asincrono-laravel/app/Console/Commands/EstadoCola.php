<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbit;
use Illuminate\Console\Command;

/** Muestra cuántos mensajes esperan en cada cola y cuántos consumidores están conectados. */
class EstadoCola extends Command
{
    protected $signature = 'miniaturas:cola';

    protected $description = 'Estado de las colas de miniaturas en RabbitMQ';

    public function handle(): int
    {
        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararColas($canal);

        $filas = [];
        foreach ([config('mensajeria.miniaturas.cola'), config('mensajeria.miniaturas.cola_errores')] as $cola) {
            // passive=true: solo consulta la cola, no la modifica
            [, $mensajes, $consumidores] = $canal->queue_declare($cola, true);
            $filas[] = [$cola, $mensajes, $consumidores];
        }
        $this->table(['Cola', 'Mensajes pendientes', 'Consumidores conectados'], $filas);
        $this->line('Consultado a las '.now()->format('H:i:s').' en '.config('mensajeria.rabbitmq.host').':'.config('mensajeria.rabbitmq.port'));

        $canal->close();
        $conexion->close();

        return self::SUCCESS;
    }
}
