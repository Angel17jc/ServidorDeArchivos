<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Services\ClienteL5;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Archivos de la tienda (sus binarios viven en el Nodo L5).
 * Todas las rutas exigen autenticación (auth:sanctum) y cada acción pasa por ArchivoPolicy.
 */
class ArchivoController extends Controller
{
    /** Consulta: el cliente ve solo sus archivos; el administrador, todos. */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Archivo::class);

        $usuario = $request->user();
        $archivos = Archivo::with('propietario')
            ->when(! $usuario->esAdmin(), fn ($consulta) => $consulta->where('user_id', $usuario->id))
            ->orderBy('id')
            ->get();

        return response()->json($archivos->map->aRespuesta()->values(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Consulta de un archivo: solo su propietario o un administrador (si no, 403). */
    public function show(Archivo $archivo): JsonResponse
    {
        Gate::authorize('view', $archivo);

        return response()->json($archivo->load('propietario')->aRespuesta(), 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Modificación: sube el archivo a L5 y registra al usuario autenticado como propietario. */
    public function store(Request $request, ClienteL5 $l5): JsonResponse
    {
        Gate::authorize('create', Archivo::class);

        $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $guardado = $l5->subir($request->file('file'));

        $archivo = Archivo::create([
            'user_id' => $request->user()->id, // el propietario sale del token, nunca del cuerpo de la petición
            'nombre_original' => $request->file('file')->getClientOriginalName(),
            'storage_key' => $guardado['storage_key'],
            'mime_type' => $guardado['mime_type'] ?? $request->file('file')->getMimeType(),
            'size' => $guardado['size'] ?? $request->file('file')->getSize(),
        ]);

        return response()->json($archivo->load('propietario')->aRespuesta(), 201, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Modificación: solo un administrador puede eliminar (si no, 403). */
    public function destroy(Archivo $archivo, ClienteL5 $l5): Response
    {
        Gate::authorize('delete', $archivo);

        $l5->eliminar($archivo->storage_key);
        $archivo->delete();

        return response()->noContent();
    }
}
