<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Estado de una solicitud de miniatura (tabla de estados para el seguimiento por correlation_id). */
#[Fillable(['message_id', 'correlation_id', 'modo', 'imagen_id', 'ancho', 'estado', 'miniatura_key', 'error',
    'intentos', 'publicado_en', 'iniciado_en', 'completado_en'])]
class Procesamiento extends Model
{
    public const PENDIENTE = 'pendiente';

    public const PROCESANDO = 'procesando';

    public const COMPLETADO = 'completado';

    public const ERROR = 'error';

    // Milisegundos en las fechas para medir tiempos con precisión
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected function casts(): array
    {
        return ['publicado_en' => 'datetime', 'iniciado_en' => 'datetime', 'completado_en' => 'datetime'];
    }

    public function imagen(): BelongsTo
    {
        return $this->belongsTo(Imagen::class);
    }

    public function aRespuesta(): array
    {
        $ms = fn ($desde, $hasta) => $desde && $hasta ? (int) round($desde->diffInMilliseconds($hasta)) : null;

        return [
            'message_id' => $this->message_id,
            'correlation_id' => $this->correlation_id,
            'modo' => $this->modo,
            'estado' => $this->estado,
            'imagen' => ['id' => $this->imagen_id, 'producto' => $this->imagen?->producto],
            'ancho' => $this->ancho,
            'miniatura' => $this->miniatura_key ? ['storage_key' => $this->miniatura_key, 'url' => '/files/'.$this->miniatura_key] : null,
            'error' => $this->error,
            'intentos' => $this->intentos,
            'publicado_en' => $this->publicado_en?->format('Y-m-d\TH:i:s.v\Z'),
            'completado_en' => $this->completado_en?->format('Y-m-d\TH:i:s.v\Z'),
            'espera_en_cola_ms' => $ms($this->publicado_en, $this->iniciado_en),
            'duracion_total_ms' => $ms($this->publicado_en, $this->completado_en),
        ];
    }
}
