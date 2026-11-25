<?php

namespace App\Enrutamiento;

/**
 * Destinos posibles de un mensaje. El valor es la routing key con la que se publica
 * en el exchange directo archivos.eventos; cada routing key está enlazada a una sola cola.
 */
enum Destino: string
{
    case Miniaturas = 'miniaturas';
    case Comprobantes = 'comprobantes';
    case Limpieza = 'limpieza';
    case NoEnrutables = 'no_enrutables';

    public function routingKey(): string
    {
        return $this->value;
    }

    public function cola(): string
    {
        return 'eventos.'.$this->value;
    }

    /** Proceso que atiende la cola. */
    public function consumidor(): string
    {
        return match ($this) {
            self::Miniaturas => 'Consumidor de miniaturas',
            self::Comprobantes => 'Consumidor de comprobantes',
            self::Limpieza => 'Consumidor de limpieza',
            self::NoEnrutables => 'Revisión manual',
        };
    }

    /** Destinos atendidos por un consumidor automático (no_enrutables espera revisión manual). */
    public static function consumibles(): array
    {
        return [self::Miniaturas, self::Comprobantes, self::Limpieza];
    }
}
