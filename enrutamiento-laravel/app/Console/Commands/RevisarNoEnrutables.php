<?php

namespace App\Console\Commands;

use App\Enrutamiento\Destino;
use App\Mensajeria\ConexionRabbit;
use Illuminate\Console\Command;

/**
 * Revisión manual de la cola eventos.no_enrutables: muestra los mensajes que el enrutador
 * no pudo asignar y el motivo. Por defecto no los retira (se devuelven a la cola);
 * con --retirar se confirman y salen de la cola una vez revisados.
 */
class RevisarNoEnrutables extends Command
{
    protected $signature = 'eventos:revisar {--retirar : Retirar de la cola los mensajes revisados}';

    protected $description = 'Muestra los mensajes de la cola de no enrutables';

    public function handle(): int
    {
        $cola = Destino::NoEnrutables->cola();
        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararTopologia($canal);

        $filas = [];
        $ultimo = null;
        while ($amqp = $canal->basic_get($cola)) {
            $m = json_decode($amqp->getBody(), true) ?? [];
            $filas[] = [$m['evento_id'] ?? '?', $m['tipo'] ?? '?', $m['correlation_id'] ?? '?', $m['enrutamiento']['motivo'] ?? '?'];
            $ultimo = $amqp->getDeliveryTag();
        }

        if ($ultimo !== null) {
            $this->option('retirar')
                ? $canal->basic_ack($ultimo, true)          // confirma todos los leídos: salen de la cola
                : $canal->basic_nack($ultimo, true, true);  // los devuelve a la cola sin cambios
        }

        $this->line("Cola {$cola} · ".count($filas).' mensaje(s) pendiente(s) de revisión manual');
        if ($filas) {
            $this->table(['Evento', 'Tipo', 'correlation_id', 'Motivo del enrutador'], $filas);
            $this->line($this->option('retirar') ? 'Mensajes retirados de la cola.' : 'Los mensajes siguen en la cola (use --retirar después de revisarlos).');
        }

        $canal->close();
        $conexion->close();

        return self::SUCCESS;
    }
}
