<?php

namespace App\Mensajeria;

use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;

/**
 * Publica mensajes persistentes en el exchange archivos.eventos con la routing key elegida.
 *  - mandatory: si ninguna cola está enlazada a esa routing key, RabbitMQ devuelve el mensaje en vez de perderlo;
 *  - publisher confirms: se espera la confirmación del broker antes de responder al cliente.
 * En ambos casos de fallo se lanza una excepción y la API responde 503 (fallo de transporte).
 */
class PublicadorRabbit implements PublicadorMensajes
{
    public function publicar(string $routingKey, array $mensaje, int $prioridad): void
    {
        $conexion = ConexionRabbit::abrir();
        try {
            $canal = $conexion->channel();
            ConexionRabbit::declararTopologia($canal);

            $confirmado = false;
            $devuelto = false;
            $canal->set_ack_handler(function () use (&$confirmado) {
                $confirmado = true;
            });
            $canal->set_nack_handler(function () use (&$confirmado) {
                $confirmado = false;
            });
            $canal->set_return_listener(function () use (&$devuelto) {
                $devuelto = true;
            });
            $canal->confirm_select();

            $amqp = new AMQPMessage(json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // el mensaje se guarda en disco
                'message_id' => $mensaje['message_id'],
                'correlation_id' => $mensaje['correlation_id'],
                'type' => $mensaje['tipo'],
                'priority' => $prioridad,
                'timestamp' => time(),
            ]);

            $canal->basic_publish($amqp, config('mensajeria.enrutamiento.exchange'), $routingKey, true);
            $canal->wait_for_pending_acks_returns(5);
            $canal->close();

            if ($devuelto) {
                throw new RuntimeException("Ninguna cola está enlazada a la routing key «{$routingKey}»");
            }
            if (! $confirmado) {
                throw new RuntimeException('RabbitMQ no confirmó la recepción del mensaje');
            }
        } finally {
            $conexion->close();
        }
    }
}
