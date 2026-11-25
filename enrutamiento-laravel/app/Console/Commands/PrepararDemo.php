<?php

namespace App\Console\Commands;

use App\Models\ArchivoDemo;
use App\Services\ClienteL5;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sube al Nodo L5 los archivos ficticios que usan las pruebas (una foto de producto, un comprobante
 * de pago en PDF y una foto que luego se da de baja) y escribe sus claves en la colección de Postman.
 */
class PrepararDemo extends Command
{
    protected $signature = 'demo:preparar';

    protected $description = 'Sube a L5 los archivos de prueba y actualiza las variables de la colección de Postman';

    private const COLECCION = 'postman/Enrutamiento.postman_collection.json';

    public function handle(ClienteL5 $l5): int
    {
        $archivos = [
            'imagen' => ['Foto de producto · Camisa azul', $this->foto('Camisa azul', [29, 78, 216]), 'camisa-azul.jpg', 'image/jpeg', 'products'],
            'comprobante' => ['Comprobante de pago · pedido PED-1002', $this->comprobante(), 'comprobante-ped-1002.pdf', 'application/pdf', 'payments'],
            'eliminar' => ['Foto de producto retirado · Gorra roja', $this->foto('Gorra roja', [190, 30, 45]), 'gorra-roja.jpg', 'image/jpeg', 'products'],
        ];

        $filas = [];
        foreach ($archivos as $rol => [$descripcion, $contenido, $nombre, $mime, $carpeta]) {
            try {
                $subida = $l5->subir($contenido, $nombre, $mime, $carpeta, 'demo-preparar');
            } catch (Throwable $e) {
                $this->error($e->getMessage().'. ¿Está activo el Nodo L5 y es correcta L5_FILES_API_KEY?');

                return self::FAILURE;
            }
            ArchivoDemo::updateOrCreate(['rol' => $rol], [
                'descripcion' => $descripcion, 'storage_key' => $subida['storage_key'], 'mime_type' => $mime, 'bytes' => strlen($contenido),
            ]);
            $filas[] = [$rol, $descripcion, $subida['storage_key'], number_format(strlen($contenido), 0, ',', '.').' B'];
        }

        $this->table(['Rol', 'Archivo ficticio', 'storage_key en L5', 'Tamaño'], $filas);
        $this->actualizarColeccion();

        return self::SUCCESS;
    }

    /** Escribe las claves y tamaños en las variables de la colección para que las peticiones funcionen sin editarlas. */
    private function actualizarColeccion(): void
    {
        $ruta = base_path(self::COLECCION);
        $coleccion = json_decode((string) file_get_contents($ruta), true);
        $valores = [];
        foreach (ArchivoDemo::all() as $a) {
            $valores["clave_{$a->rol}"] = $a->storage_key;
            $valores["tamano_{$a->rol}"] = (string) $a->bytes;
        }
        foreach ($coleccion['variable'] as &$variable) {
            $variable['value'] = $valores[$variable['key']] ?? $variable['value'];
        }
        unset($variable);
        file_put_contents($ruta, json_encode($coleccion, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        $this->info('Variables actualizadas en '.self::COLECCION.': vuelva a importar la colección en Postman.');
    }

    /** Foto ficticia de 1200x900 generada con GD. */
    private function foto(string $producto, array $rgb): string
    {
        $img = imagecreatetruecolor(1200, 900);
        $fondo = imagecolorallocate($img, 241, 245, 249);
        imagefill($img, 0, 0, $fondo);
        imagefilledrectangle($img, 330, 220, 870, 760, imagecolorallocate($img, ...$rgb));
        imagefilledellipse($img, 600, 220, 300, 160, $fondo);
        imagestring($img, 5, 530, 800, $producto, imagecolorallocate($img, 31, 41, 55));
        ob_start();
        imagejpeg($img, null, 90);

        return (string) ob_get_clean();
    }

    /** Comprobante de pago ficticio: PDF 1.4 de una página escrito a mano (sin librerías). */
    private function comprobante(): string
    {
        $lineas = [
            [18, 'Comprobante de pago - Tienda en linea'],
            [12, 'Pedido: PED-1002'],
            [12, 'Cliente: Angel (datos ficticios)'],
            [12, 'Metodo: transferencia bancaria'],
            [12, 'Total pagado: USD 45.90'],
            [10, 'Documento generado para la practica IS-803 tema 3.2'],
        ];
        $contenido = '';
        $y = 740;
        foreach ($lineas as [$tam, $texto]) {
            $contenido .= sprintf("BT /F1 %d Tf 72 %d Td (%s) Tj ET\n", $tam, $y, addcslashes($texto, '()\\'));
            $y -= $tam + 14;
        }

        $objetos = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($contenido)." >>\nstream\n{$contenido}endstream",
        ];
        $pdf = "%PDF-1.4\n";
        $posiciones = [];
        foreach ($objetos as $i => $objeto) {
            $posiciones[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$objeto}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objetos) + 1)."\n0000000000 65535 f \n";
        foreach ($posiciones as $p) {
            $pdf .= sprintf("%010d 00000 n \n", $p);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objetos) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
