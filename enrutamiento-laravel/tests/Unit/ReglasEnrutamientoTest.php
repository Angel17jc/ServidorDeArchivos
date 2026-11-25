<?php

namespace Tests\Unit;

use App\Enrutamiento\Destino;
use App\Enrutamiento\EnrutadorContenido;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Tabla de reglas del Content-Based Router, comprobada caso por caso. */
class ReglasEnrutamientoTest extends TestCase
{
    public static function reglas(): array
    {
        return [
            'R1 imagen .jpg' => ['imagen_producto', 'products/a.jpg', 'R1', Destino::Miniaturas],
            'R1 imagen .webp' => ['imagen_producto', 'products/a.webp', 'R1', Destino::Miniaturas],
            'R2 comprobante .pdf' => ['comprobante_pago', 'payments/a.pdf', 'R2', Destino::Comprobantes],
            'R3 eliminado' => ['archivo_eliminado', 'products/a.jpg', 'R3', Destino::Limpieza],
            'R4 tipo desconocido' => ['factura_proveedor', 'payments/a.pdf', 'R4', Destino::NoEnrutables],
            'R4 imagen que es PDF' => ['imagen_producto', 'payments/a.pdf', 'R4', Destino::NoEnrutables],
            'R4 comprobante que es imagen' => ['comprobante_pago', 'products/a.png', 'R4', Destino::NoEnrutables],
            'R4 tipo en mayúsculas' => ['IMAGEN_PRODUCTO', 'products/a.jpg', 'R4', Destino::NoEnrutables],
        ];
    }

    #[DataProvider('reglas')]
    public function test_regla_y_destino(string $tipo, string $clave, string $regla, Destino $destino): void
    {
        $decision = (new EnrutadorContenido)->decidir(['tipo' => $tipo, 'datos' => ['storage_key' => $clave]]);

        $this->assertSame($regla, $decision->regla);
        $this->assertSame($destino, $decision->destino);
    }

    public function test_prioridad_amqp(): void
    {
        $enrutador = new EnrutadorContenido;

        $this->assertSame([9, 5, 1], [$enrutador->prioridadAmqp('alta'), $enrutador->prioridadAmqp('normal'), $enrutador->prioridadAmqp('baja')]);
    }
}
