<?php

namespace App\Consumidores;

use App\Services\ClienteL5;

/** Cola eventos.limpieza: elimina del Nodo L5 el binario de un archivo dado de baja en el backend. */
class ConsumidorLimpieza implements ConsumidorEvento
{
    public function __construct(private readonly ClienteL5 $l5) {}

    public function procesar(array $mensaje): array
    {
        $clave = $mensaje['datos']['storage_key'];

        return ['detalle' => $this->l5->eliminar($clave, $mensaje['correlation_id'])
            ? "Archivo {$clave} eliminado de L5"
            : "El archivo {$clave} ya no existía en L5: no había nada que borrar"];
    }
}
