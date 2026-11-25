<?php

namespace App\Enrutamiento;

/**
 * MESSAGE FILTER: decide si un mensaje válido debe procesarse.
 * Se ejecuta después de la validación y antes del enrutador; un mensaje que no cumple
 * una condición no se publica y, por lo tanto, no llega a ningún consumidor.
 *
 *   F1  estado = "confirmado"        solo se procesan operaciones confirmadas por el backend (L3)
 *   F2  datos.tamano_bytes ≤ 10 MB   el mismo límite que aplica el Nodo L5
 *
 * Un mensaje filtrado es un rechazo por regla de negocio, no un error técnico:
 * se registra como "filtrado" (HTTP 200), distinto de "invalido" (422) y de "fallo_transporte" (503).
 */
class FiltroMensajes
{
    public const ESTADO_ACEPTADO = 'confirmado';

    /** Devuelve null si el mensaje pasa el filtro, o la decisión de rechazo con la regla incumplida. */
    public function evaluar(array $mensaje): ?Decision
    {
        if ($mensaje['estado'] !== self::ESTADO_ACEPTADO) {
            return new Decision(Decision::FILTRADO, 'F1',
                "La operación está «{$mensaje['estado']}»: solo se procesan eventos con estado «".self::ESTADO_ACEPTADO.'».');
        }

        $maximo = (int) config('mensajeria.enrutamiento.tamano_maximo_bytes');
        $tamano = $mensaje['datos']['tamano_bytes'] ?? null;
        if ($tamano !== null && (int) $tamano > $maximo) {
            return new Decision(Decision::FILTRADO, 'F2',
                sprintf('El archivo pesa %s bytes y supera el máximo de %s bytes.', number_format((int) $tamano, 0, ',', '.'), number_format($maximo, 0, ',', '.')));
        }

        return null;
    }
}
