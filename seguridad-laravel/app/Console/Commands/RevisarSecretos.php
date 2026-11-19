<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Prueba P4: revisa que ningún secreto quede expuesto en el repositorio.
 * Nunca imprime los valores secretos: solo nombres de variables y rutas de archivos.
 */
class RevisarSecretos extends Command
{
    protected $signature = 'seguridad:revisar-secretos';

    protected $description = 'Comprueba que .env esté excluido de Git y que ningún archivo versionable contenga los secretos';

    /** Variables cuyo valor nunca debe aparecer fuera de .env */
    private const SECRETAS = [
        'APP_KEY', 'L5_FILES_API_KEY', 'DB_PASSWORD',
        'DEMO_CLIENTE_PASSWORD', 'DEMO_CLIENTE2_PASSWORD', 'DEMO_ADMIN_PASSWORD',
    ];

    public function handle(): int
    {
        $env = $this->leerEnv(base_path('.env'));
        $ejemplo = $this->leerEnv(base_path('.env.example'));
        $raiz = trim($this->git(['rev-parse', '--show-toplevel']));
        $prefijo = trim($this->git(['rev-parse', '--show-prefix']));
        $fallos = 0;
        $filas = [];

        // 1. .env ignorado por Git y no versionado
        $ignorado = $this->git(['check-ignore', '-q', '.env'], devolverCodigo: true) === 0;
        $versionado = trim($this->git(['ls-files', '--', '.env'])) !== '';
        $filas[] = ['.env excluido de Git (.gitignore)', $ignorado && ! $versionado ? 'OK' : 'FALLA'];
        $fallos += $ignorado && ! $versionado ? 0 : 1;

        // 2. Archivos que se subirían: versionados + nuevos no ignorados (en todo el repositorio)
        $archivos = array_filter(explode("\n", $this->git(['-C', $raiz, 'ls-files', '--cached', '--others', '--exclude-standard'])));
        $filas[] = ['Archivos versionables revisados', count($archivos)];

        foreach (self::SECRETAS as $nombre) {
            $valor = $env[$nombre] ?? '';
            if (strlen($valor) < 6) {
                $filas[] = ["{$nombre} sin valor en .env", 'omitida'];

                continue;
            }
            $hallados = [];
            foreach ($archivos as $archivo) {
                $ruta = $raiz.'/'.$archivo;
                if (is_file($ruta) && filesize($ruta) < 5_000_000 && str_contains((string) file_get_contents($ruta), $valor)) {
                    $hallados[] = $archivo;
                }
            }
            $filas[] = ["Valor de {$nombre} fuera de .env", $hallados ? 'FALLA: '.implode(', ', $hallados) : 'OK (no aparece)'];
            $fallos += $hallados ? 1 : 0;

            // .env.example solo debe traer el nombre, sin el valor real
            $soloNombre = array_key_exists($nombre, $ejemplo) && ($ejemplo[$nombre] ?? '') !== $valor;
            $filas[] = ["{$nombre} en .env.example sin valor real", $soloNombre ? 'OK' : 'REVISAR'];
        }

        // 3. Sin trazas en las respuestas de error
        $depuracion = strtolower($env['APP_DEBUG'] ?? 'true');
        $filas[] = ['APP_DEBUG=false (sin trazas en errores)', in_array($depuracion, ['false', '0'], true) ? 'OK' : 'FALLA'];
        $fallos += in_array($depuracion, ['false', '0'], true) ? 0 : 1;

        $this->table(['Comprobación', 'Resultado'], $filas);
        $this->line("Proyecto: {$prefijo}  ·  los valores secretos no se muestran.");

        if ($fallos > 0) {
            $this->error("{$fallos} comprobación(es) fallida(s).");

            return self::FAILURE;
        }
        $this->info('P4 superada: no hay secretos expuestos en los archivos versionables.');

        return self::SUCCESS;
    }

    private function leerEnv(string $ruta): array
    {
        $valores = [];
        foreach (is_file($ruta) ? file($ruta, FILE_IGNORE_NEW_LINES) : [] as $linea) {
            if (preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)$/', $linea, $m)) {
                $valores[$m[1]] = trim($m[2], " \t\"'");
            }
        }

        return $valores;
    }

    private function git(array $argumentos, bool $devolverCodigo = false): string|int
    {
        $proceso = new Process(['git', ...$argumentos], base_path());
        $proceso->run();

        return $devolverCodigo ? $proceso->getExitCode() : $proceso->getOutput();
    }
}
