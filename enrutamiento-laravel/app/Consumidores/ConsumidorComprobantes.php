<?php

namespace App\Consumidores;

use App\Models\Comprobante;
use App\Services\ClienteL5;
use RuntimeException;

/**
 * Cola eventos.comprobantes: descarga el comprobante de L5, comprueba que sea un PDF real,
 * calcula su huella SHA-256 y lo registra como «por_revisar» para que el backend (L3) confirme el pago.
 */
class ConsumidorComprobantes implements ConsumidorEvento
{
    public function __construct(private readonly ClienteL5 $l5) {}

    public function procesar(array $mensaje): array
    {
        $clave = $mensaje['datos']['storage_key'];
        $pdf = $this->l5->descargar($clave, $mensaje['correlation_id']);

        if (! str_starts_with($pdf, '%PDF-')) {
            throw new RuntimeException('El archivo no es un PDF válido');
        }

        $version = substr($pdf, 5, 3);
        $sha256 = hash('sha256', $pdf);
        $referencia = $mensaje['datos']['referencia'] ?? 'sin referencia';

        Comprobante::updateOrCreate(['storage_key' => $clave], [
            'referencia' => $referencia, 'correlation_id' => $mensaje['correlation_id'], 'version_pdf' => $version,
            'bytes' => strlen($pdf), 'sha256' => $sha256, 'estado' => 'por_revisar',
        ]);

        return ['detalle' => sprintf('PDF %s válido (%d B, sha256 %s…): comprobante de %s registrado por revisar',
            $version, strlen($pdf), substr($sha256, 0, 12), $referencia)];
    }
}
