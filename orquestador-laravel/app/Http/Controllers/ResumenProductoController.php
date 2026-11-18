<?php

namespace App\Http\Controllers;

use App\Exceptions\DependenciaNoDisponible;
use App\Services\ClienteDependencias;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/resumen-producto/{id} · Adaptación al proyecto de la tienda.
 * Orquesta el catálogo de productos y el Nodo L5 (servidor de archivos):
 * la segunda consulta depende de la primera, porque necesita la clave de la imagen.
 */
class ResumenProductoController extends Controller
{
    private const CLAVE_IMAGEN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp)$/D';

    public function __invoke(Request $request, string $id, ClienteDependencias $dependencias): JsonResponse
    {
        $cid = $request->attributes->get('correlation_id');

        // 1. Catálogo: datos del producto y la clave de su imagen en L5
        $producto = $dependencias->obtenerJson('catalogo', config('orquestacion.catalogo_url').'/productos/'.$id, $cid);

        $clave = $producto['imagen'] ?? null;
        if ((string) ($producto['id'] ?? '') !== $id || ! is_string($producto['nombre'] ?? null)
            || ! is_numeric($producto['precio'] ?? null) || ! is_string($clave) || ! preg_match(self::CLAVE_IMAGEN, $clave)) {
            throw new DependenciaNoDisponible('catalogo', 'contenido inválido');
        }

        // 2. Nodo L5: comprobar que la imagen existe, sin descargarla (HEAD /files/<clave>)
        $archivo = $dependencias->obtenerCabeceras('archivos-L5', config('orquestacion.archivos_url').'/files/'.$clave, $cid);

        $tipo = strtolower(trim(explode(';', $archivo->header('Content-Type'))[0]));
        $tamano = $archivo->header('Content-Length');
        if (! str_starts_with($tipo, 'image/') || ! ctype_digit($tamano)) {
            throw new DependenciaNoDisponible('archivos-L5', 'el recurso no es una imagen válida');
        }

        // 3. Composición: producto e imagen en una sola respuesta
        return response()->json([
            'producto_id' => (int) $id,
            'producto' => ['id' => (int) $producto['id'], 'nombre' => $producto['nombre'], 'precio' => (float) $producto['precio']],
            'imagen' => ['storage_key' => $clave, 'url' => '/files/'.$clave, 'mime_type' => $tipo, 'size' => (int) $tamano],
            'correlation_id' => $cid,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
