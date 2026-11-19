<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Enmascarar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/** Autenticación con tokens de Laravel Sanctum. */
class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $usuario = User::where('email', $datos['email'])->first();

        if (! $usuario || ! Hash::check($datos['password'], $usuario->password)) {
            // Se registra el intento sin la contraseña y con el correo enmascarado
            Log::warning('Inicio de sesión fallido', ['email' => Enmascarar::correo($datos['email']), 'ip' => $request->ip()]);

            return response()->json(['error' => 'Credenciales inválidas'], 401);
        }

        $minutos = (int) config('sanctum.expiration');
        $token = $usuario->createToken('api', ['*'], $minutos > 0 ? now()->addMinutes($minutos) : null);

        return response()->json([
            'token' => $token->plainTextToken, // se muestra una sola vez; en la base solo queda su hash
            'tipo' => 'Bearer',
            'expira_en_minutos' => $minutos ?: null,
            'usuario' => $this->usuario($usuario),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->usuario($request->user()));
    }

    /** Revoca el token usado en esta petición. */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    private function usuario(User $usuario): array
    {
        return ['id' => $usuario->id, 'nombre' => $usuario->name, 'rol' => $usuario->rol];
    }
}
