<?php

namespace App\Enrutamiento;

/**
 * CONTENT-BASED ROUTER: inspecciona el tipo y el contenido del mensaje y elige su destino.
 *
 *   R1  tipo = imagen_producto  y el archivo es una imagen (.jpg, .png, .webp)  → miniaturas
 *   R2  tipo = comprobante_pago y el archivo es un PDF (.pdf)                   → comprobantes
 *   R3  tipo = archivo_eliminado                                                → limpieza
 *   R4  cualquier otro caso (tipo desconocido o contenido que no coincide)      → no_enrutables
 *
 * R4 nunca descarta: el mensaje se publica en eventos.no_enrutables para revisarlo a mano.
 * La prioridad del mensaje se traduce a prioridad AMQP: dentro de cada cola se entrega primero lo urgente.
 */
class EnrutadorContenido
{
    private const IMAGEN = '/\.(jpg|png|webp)$/D';

    private const PDF = '/\.pdf$/D';

    public const PRIORIDADES = ['alta' => 9, 'normal' => 5, 'baja' => 1];

    public function decidir(array $mensaje): Decision
    {
        $tipo = $mensaje['tipo'];
        $clave = $mensaje['datos']['storage_key'];
        $esImagen = preg_match(self::IMAGEN, $clave) === 1;
        $esPdf = preg_match(self::PDF, $clave) === 1;

        return match (true) {
            $tipo === 'imagen_producto' && $esImagen => $this->enrutado('R1', Destino::Miniaturas,
                'Imagen de producto: se genera su miniatura para el catálogo.'),

            $tipo === 'comprobante_pago' && $esPdf => $this->enrutado('R2', Destino::Comprobantes,
                'Comprobante de pago en PDF: se verifica y se registra para la revisión del pedido.'),

            $tipo === 'archivo_eliminado' => $this->enrutado('R3', Destino::Limpieza,
                'Archivo dado de baja en el backend: se elimina su binario del Nodo L5.'),

            in_array($tipo, ['imagen_producto', 'comprobante_pago'], true) => $this->noEnrutable(
                "El contenido no coincide con el tipo «{$tipo}» (archivo {$this->extension($clave)}): queda para revisión manual."),

            default => $this->noEnrutable("Tipo «{$tipo}» no reconocido: queda para revisión manual."),
        };
    }

    public function prioridadAmqp(string $prioridad): int
    {
        return self::PRIORIDADES[$prioridad] ?? self::PRIORIDADES['normal'];
    }

    private function enrutado(string $regla, Destino $destino, string $motivo): Decision
    {
        return new Decision(Decision::ENRUTADO, $regla, $motivo, $destino);
    }

    private function noEnrutable(string $motivo): Decision
    {
        return new Decision(Decision::NO_ENRUTABLE, 'R4', $motivo, Destino::NoEnrutables);
    }

    private function extension(string $clave): string
    {
        return '.'.pathinfo($clave, PATHINFO_EXTENSION);
    }
}
