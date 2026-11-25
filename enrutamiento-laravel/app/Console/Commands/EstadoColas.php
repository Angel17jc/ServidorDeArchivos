<?php

namespace App\Console\Commands;

use App\Enrutamiento\Destino;
use App\Mensajeria\ConexionRabbit;
use Illuminate\Console\Command;

/** Muestra, por cola, su routing key, los mensajes pendientes y los consumidores conectados. */
class EstadoColas extends Command
{
    protected $signature = 'eventos:colas';

    protected $description = 'Estado de las colas del enrutamiento en RabbitMQ';

    public function handle(): int
    {
        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararTopologia($canal);

        $filas = [];
        foreach (Destino::cases() as $destino) {
            // passive=true: solo consulta la cola, no la modifica
            [, $mensajes, $consumidores] = $canal->queue_declare($destino->cola(), true);
            $filas[] = [$destino->cola(), $destino->routingKey(), $mensajes, $consumidores, $destino->consumidor()];
        }
        $errores = config('mensajeria.enrutamiento.cola_errores');
        [, $mensajes, $consumidores] = $canal->queue_declare($errores, true);
        $filas[] = [$errores, '(nack de un consumidor)', $mensajes, $consumidores, 'Revisión técnica'];

        $this->line('Exchange '.config('mensajeria.enrutamiento.exchange').' (direct) · consultado a las '.now()->format('H:i:s'));
        $this->table(['Cola', 'Routing key', 'Pendientes', 'Consumidores', 'Atiende'], $filas);

        $canal->close();
        $conexion->close();

        return self::SUCCESS;
    }
}
