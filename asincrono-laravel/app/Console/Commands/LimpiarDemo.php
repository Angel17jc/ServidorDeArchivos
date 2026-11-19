<?php

namespace App\Console\Commands;

use App\Mensajeria\ConexionRabbit;
use App\Models\Imagen;
use App\Models\Procesamiento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/** Deja el entorno limpio: borra de L5 las imágenes y miniaturas de la demostración, vacía las colas y las tablas. */
class LimpiarDemo extends Command
{
    protected $signature = 'demo:limpiar';

    protected $description = 'Borra de L5 los archivos de la demostración y vacía colas y tablas';

    public function handle(): int
    {
        $l5 = config('mensajeria.l5');
        $claves = Procesamiento::whereNotNull('miniatura_key')->pluck('miniatura_key')
            ->merge(Imagen::pluck('storage_key'))->unique();

        $borrados = 0;
        foreach ($claves as $clave) {
            $r = Http::withHeaders(['x-api-key' => (string) $l5['key']])->timeout(10)->delete($l5['url'].'/files/'.rawurlencode($clave));
            $borrados += $r->status() === 204 ? 1 : 0;
        }

        $conexion = ConexionRabbit::abrir();
        $canal = $conexion->channel();
        ConexionRabbit::declararColas($canal);
        $purgados = (int) $canal->queue_purge(config('mensajeria.miniaturas.cola')) + (int) $canal->queue_purge(config('mensajeria.miniaturas.cola_errores'));
        $canal->close();
        $conexion->close();

        Procesamiento::query()->delete();
        Imagen::query()->delete();

        $this->info("Archivos borrados en L5: {$borrados} · mensajes purgados de las colas: {$purgados} · tablas vacías.");

        return self::SUCCESS;
    }
}
