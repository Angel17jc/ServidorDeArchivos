<?php

namespace Database\Seeders;

use App\Models\Archivo;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /**
     * Usuarios y archivos de demostración (datos ficticios).
     * Las contraseñas vienen de .env (DEMO_*_PASSWORD); si faltan, el seeder se detiene
     * en lugar de usar una contraseña escrita en el código.
     */
    public function run(): void
    {
        $usuarios = [];
        foreach (config('demo.usuarios') as $datos) {
            if (empty($datos['password'])) {
                throw new RuntimeException("Falta la contraseña de {$datos['email']}: defina DEMO_*_PASSWORD en .env");
            }
            $usuarios[$datos['rol'].':'.$datos['email']] = User::updateOrCreate(
                ['email' => $datos['email']],
                ['name' => $datos['nombre'], 'rol' => $datos['rol'], 'password' => $datos['password']],
            );
        }

        [$ana, $luis] = array_values(array_filter($usuarios, fn (User $u) => $u->rol === User::ROL_CLIENTE));

        // Archivos ficticios: dos de Ana y uno de Luis (claves con el formato de L5)
        $archivos = [
            [$ana, 'camisa-azul.jpg', '1b9d6bcd-bbfd-4b2d-9b5d-ab8dfbbd4bed.jpg', 'image/jpeg', 48213],
            [$ana, 'comprobante-pedido-1001.pdf', '6ec0bd7f-11c0-43da-975e-2a8ad9ebae0b.pdf', 'application/pdf', 91544],
            [$luis, 'zapatos-cafe.png', 'c9bf9e57-1685-4c89-bafb-ff5af830be8a.png', 'image/png', 120330],
        ];
        foreach ($archivos as [$propietario, $nombre, $clave, $tipo, $tamano]) {
            Archivo::updateOrCreate(['storage_key' => $clave], [
                'user_id' => $propietario->id, 'nombre_original' => $nombre, 'mime_type' => $tipo, 'size' => $tamano,
            ]);
        }
    }
}
