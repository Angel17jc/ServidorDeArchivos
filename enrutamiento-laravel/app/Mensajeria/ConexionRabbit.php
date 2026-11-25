<?php

namespace App\Mensajeria;

use App\Enrutamiento\Destino;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Conexión con RabbitMQ y declaración de la topología del enrutamiento:
 *
 *   exchange archivos.eventos (direct, durable)
 *     ├─ routing key miniaturas     → cola eventos.miniaturas      → Consumidor de miniaturas
 *     ├─ routing key comprobantes   → cola eventos.comprobantes    → Consumidor de comprobantes
 *     ├─ routing key limpieza       → cola eventos.limpieza        → Consumidor de limpieza
 *     └─ routing key no_enrutables  → cola eventos.no_enrutables   → revisión manual
 *
 *   Si un consumidor rechaza un mensaje (nack sin reencolar), RabbitMQ lo mueve a eventos.errores.
 *   Todas las colas son durables y aceptan prioridad AMQP de 0 a 10.
 *
 * Productor y consumidores declaran lo mismo: la operación es idempotente.
 */
class ConexionRabbit
{
    public static function abrir(): AMQPStreamConnection
    {
        $c = config('mensajeria.rabbitmq');

        return new AMQPStreamConnection($c['host'], $c['port'], (string) $c['user'], (string) $c['password'], $c['vhost']);
    }

    public static function declararTopologia(AMQPChannel $canal): void
    {
        $exchange = config('mensajeria.enrutamiento.exchange');
        $errores = config('mensajeria.enrutamiento.cola_errores');

        // direct: la routing key debe coincidir exactamente con la del enlace (binding)
        $canal->exchange_declare($exchange, 'direct', false, true, false);
        $canal->queue_declare($errores, false, true, false, false);

        foreach (Destino::cases() as $destino) {
            $canal->queue_declare($destino->cola(), false, true, false, false, false, new AMQPTable([
                'x-max-priority' => 10,                  // la prioridad del mensaje ordena la entrega dentro de la cola
                'x-dead-letter-exchange' => '',          // exchange por defecto
                'x-dead-letter-routing-key' => $errores, // destino de los mensajes rechazados por un consumidor
            ]));
            $canal->queue_bind($destino->cola(), $exchange, $destino->routingKey());
        }
    }

    /** Colas de la topología, en orden de presentación. */
    public static function colas(): array
    {
        return [...array_map(fn (Destino $d) => $d->cola(), Destino::cases()), config('mensajeria.enrutamiento.cola_errores')];
    }
}
