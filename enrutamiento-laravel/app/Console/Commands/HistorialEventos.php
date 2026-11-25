<?php

namespace App\Console\Commands;

use App\Models\Evento;
use Illuminate\Console\Command;

/** Constancia de los últimos mensajes: decisión tomada (validación, filtro o enrutador) y consumidor que los procesó. */
class HistorialEventos extends Command
{
    protected $signature = 'eventos:historial {--n=15 : Cantidad de mensajes}';

    protected $description = 'Muestra la decisión y el procesamiento de los últimos mensajes';

    public function handle(): int
    {
        $filas = Evento::query()->orderByDesc('id')->limit((int) $this->option('n'))->get()->reverse()->map(fn (Evento $e) => [
            $e->recibido_en?->format('H:i:s.v'),
            $e->evento_id ?? '—',
            $e->tipo ?? '(falta)',
            $e->resultado,
            $e->regla ?? '—',
            $e->destino ?? '—',
            match ($e->procesamiento) {
                Evento::PROCESADO => "{$e->destino} {$e->procesado_en?->format('H:i:s.v')}",
                Evento::ERROR => "{$e->destino} (error)",
                Evento::PENDIENTE => $e->destino === 'no_enrutables' ? 'revisión manual' : 'pendiente',
                default => 'no se publicó',
            },
        ])->values();

        // Columnas compactas para que la tabla quepa en una ventana de 120 caracteres
        $this->table(['Recibido', 'Evento', 'Tipo', 'Resultado', 'Regla', 'Destino', 'Procesado por'], $filas->all());

        return self::SUCCESS;
    }
}
