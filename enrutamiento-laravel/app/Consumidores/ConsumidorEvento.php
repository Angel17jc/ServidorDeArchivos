<?php

namespace App\Consumidores;

/** Proceso que atiende una cola. Si termina sin excepción, el mensaje se confirma (ack); si lanza, se rechaza (nack). */
interface ConsumidorEvento
{
    /** @return array{detalle: string, archivo_generado?: string} */
    public function procesar(array $mensaje): array;
}
