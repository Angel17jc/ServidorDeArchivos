<?php

namespace App\Mensajeria;

use PhpAmqpLib\Message\AMQPMessage;

/** Publica mensajes persistentes en RabbitMQ con su message_id y correlation_id en las propiedades AMQP. */
class PublicadorRabbit implements PublicadorMensajes
{
    public function publicar(string $cola, array $mensaje): void
    {
        $conexion = ConexionRabbit::abrir();
        try {
            $canal = $conexion->channel();
            ConexionRabbit::declararColas($canal);

            $amqp = new AMQPMessage(json_encode($mensaje, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT, // el mensaje se guarda en disco
                'message_id' => $mensaje['message_id'],
                'correlation_id' => $mensaje['correlation_id'],
                'type' => $mensaje['tipo'],
                'timestamp' => time(),
            ]);

            // Exchange por defecto: la routing key es el nombre de la cola
            $canal->basic_publish($amqp, '', $cola);
            $canal->close();
        } finally {
            $conexion->close();
        }
    }
}
