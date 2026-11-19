<?php

namespace App\Mensajeria;

/** Productor: publica un mensaje en una cola. Se abstrae para poder sustituirlo en las pruebas. */
interface PublicadorMensajes
{
    /** @param  array{message_id: string, correlation_id: string, tipo: string, ocurrido_en: string, payload: array}  $mensaje */
    public function publicar(string $cola, array $mensaje): void;
}
