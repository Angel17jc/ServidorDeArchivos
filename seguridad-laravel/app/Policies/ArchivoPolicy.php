<?php

namespace App\Policies;

use App\Models\Archivo;
use App\Models\User;

/**
 * Autorización sobre los archivos (se aplica después de la autenticación).
 *
 *   Acción             cliente               admin
 *   listar             sí, solo los suyos    sí, todos
 *   ver uno            solo si es suyo       sí
 *   subir              sí                    sí
 *   eliminar           no                    sí
 */
class ArchivoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return true; // el filtro "solo los suyos" se aplica en la consulta del controlador
    }

    public function view(User $usuario, Archivo $archivo): bool
    {
        return $usuario->esAdmin() || $archivo->user_id === $usuario->id;
    }

    public function create(User $usuario): bool
    {
        return in_array($usuario->rol, [User::ROL_CLIENTE, User::ROL_ADMIN], true);
    }

    public function delete(User $usuario, Archivo $archivo): bool
    {
        return $usuario->esAdmin();
    }
}
