<?php

namespace App\Mensajeria;

/** Publica un mensaje con la routing key que eligió el enrutador. Se abstrae para sustituirlo en las pruebas. */
interface PublicadorMensajes
{
    /** @param  array{message_id: string, correlation_id: string, tipo: string}  $mensaje */
    public function publicar(string $routingKey, array $mensaje, int $prioridad): void;
}
