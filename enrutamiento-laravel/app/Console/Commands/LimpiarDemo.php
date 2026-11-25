<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbit;
use App\Models\ArchivoDemo;
use App\Models\Comprobante;
use App\Models\Evento;
use App\Services\ClienteL5;
use Illuminate\Console\Command;

/** Deja el entorno limpio: borra de L5 los archivos de la demostración, vacía las colas y las tablas. */
class LimpiarDemo extends Command
{
    protected $signature = 'demo:limpiar';

    protected $description = 'Borra de L5 los archivos de la demostración y vacía colas y tablas';

    public function handle(ClienteL5 $l5): int
    {
        $claves = ArchivoDemo::pluck('storage_key')
            ->merge(Evento::whereNotNull('archivo_generado')->pluck('archivo_generado'))->unique();

        $borrados = 0;
        foreach ($claves as $clave) {
            $borrados += $l5->eliminar($clave, 'demo-limpiar') ? 1 : 0;
        }

        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararTopologia($canal);
        $purgados = 0;
        foreach (ConexionRabbit::colas() as $cola) {
            $purgados += (int) $canal->queue_purge($cola);
        }
        $canal->close();
        $conexion->close();

        Evento::query()->delete();
        Comprobante::query()->delete();
        ArchivoDemo::query()->delete();

        $this->info("Archivos borrados en L5: {$borrados} · mensajes purgados de las colas: {$purgados} · tablas vacías.");

        return self::SUCCESS;
    }
}
