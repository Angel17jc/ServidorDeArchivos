<?php

namespace App\Consumidores;

use App\Services\GeneradorMiniaturas;

/** Cola eventos.miniaturas: genera la miniatura de la imagen de producto para el catálogo. */
class ConsumidorMiniaturas implements ConsumidorEvento
{
    public function __construct(private readonly GeneradorMiniaturas $generador) {}

    public function procesar(array $mensaje): array
    {
        $r = $this->generador->generar($mensaje['datos']['storage_key'], (int) config('mensajeria.miniaturas.ancho'), $mensaje['correlation_id']);

        return [
            'detalle' => "Miniatura {$r['ancho']}x{$r['alto']} ({$r['bytes']} B) creada en L5: {$r['storage_key']}",
            'archivo_generado' => $r['storage_key'],
        ];
    }
}
