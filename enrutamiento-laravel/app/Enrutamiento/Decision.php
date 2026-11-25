<?php

namespace App\Enrutamiento;

/** Resultado del filtro o del enrutador: qué regla se aplicó, hacia dónde va el mensaje y por qué. */
final class Decision
{
    public const ENRUTADO = 'enrutado';

    public const NO_ENRUTABLE = 'no_enrutable';

    public const FILTRADO = 'filtrado';

    public function __construct(
        public readonly string $resultado,
        public readonly string $regla,
        public readonly string $motivo,
        public readonly ?Destino $destino = null,
    ) {}

    public function seEntrega(): bool
    {
        return $this->destino !== null;
    }
}
