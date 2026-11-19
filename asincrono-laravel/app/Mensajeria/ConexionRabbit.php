<?php

namespace App\Mensajeria;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Conexión con RabbitMQ y declaración de la topología del proceso de miniaturas:
 *
 *   archivos.miniaturas          cola principal, durable
 *     └─ si el consumidor rechaza un mensaje (nack sin reencolar) RabbitMQ lo mueve a:
 *   archivos.miniaturas.errores  cola de mensajes fallidos (dead letter), durable
 *
 * Productor y consumidor declaran lo mismo: la operación es idempotente.
 */
class ConexionRabbit
{
    public static function abrir(): AMQPStreamConnection
    {
        $c = config('mensajeria.rabbitmq');

        return new AMQPStreamConnection($c['host'], $c['port'], (string) $c['user'], (string) $c['password'], $c['vhost']);
    }

    public static function declararColas(AMQPChannel $canal): void
    {
        $cola = config('mensajeria.miniaturas.cola');
        $errores = config('mensajeria.miniaturas.cola_errores');

        // durable: la cola sobrevive a un reinicio del broker
        $canal->queue_declare($errores, false, true, false, false);
        $canal->queue_declare($cola, false, true, false, false, false, new AMQPTable([
            'x-dead-letter-exchange' => '',          // exchange por defecto
            'x-dead-letter-routing-key' => $errores, // destino de los mensajes rechazados
        ]));
    }
}
