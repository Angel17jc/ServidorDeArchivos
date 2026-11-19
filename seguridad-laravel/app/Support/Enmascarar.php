<?php

namespace App\Support;

/** Oculta datos sensibles antes de escribirlos en un registro. */
class Enmascarar
{
    /** Token Sanctum "12|abcdef..." → "12|abcd…(oculto)". Nunca devuelve el token completo. */
    public static function token(?string $token): string
    {
        if ($token === null || $token === '') {
            return '(sin token)';
        }

        [$id, $secreto] = str_contains($token, '|') ? explode('|', $token, 2) : ['?', $token];

        return $id.'|'.substr($secreto, 0, 4).'…(oculto)';
    }

    /** Correo "ana.lopez@tienda.test" → "a***@tienda.test". */
    public static function correo(?string $correo): string
    {
        if (! is_string($correo) || ! str_contains($correo, '@')) {
            return '(no válido)';
        }

        [$usuario, $dominio] = explode('@', $correo, 2);

        return substr($usuario, 0, 1).'***@'.$dominio;
    }
}
