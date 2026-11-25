<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Constancia de un mensaje: decisión tomada (validación, filtro o enrutador) y procesamiento en el consumidor. */
class Evento extends Model
{
    public const ENRUTADO = 'enrutado';

    public const NO_ENRUTABLE = 'no_enrutable';

    public const FILTRADO = 'filtrado';

    public const INVALIDO = 'invalido';

    public const FALLO_TRANSPORTE = 'fallo_transporte';

    public const PENDIENTE = 'pendiente';

    public const PROCESADO = 'procesado';

    public const ERROR = 'error';

    protected $table = 'eventos';

    // Milisegundos en las fechas: permiten ver el orden productor → consumidor
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $fillable = [
        'message_id', 'correlation_id', 'evento_id', 'tipo', 'prioridad', 'estado_operacion', 'datos',
        'resultado', 'regla', 'destino', 'motivo', 'procesamiento', 'consumidor', 'detalle', 'archivo_generado',
        'recibido_en', 'procesado_en',
    ];

    protected function casts(): array
    {
        return ['datos' => 'array', 'recibido_en' => 'datetime', 'procesado_en' => 'datetime'];
    }

    public function aRespuesta(): array
    {
        return [
            'message_id' => $this->message_id,
            'correlation_id' => $this->correlation_id,
            'evento_id' => $this->evento_id,
            'tipo' => $this->tipo,
            'prioridad' => $this->prioridad,
            'resultado' => $this->resultado,
            'regla' => $this->regla,
            'destino' => $this->destino,
            'motivo' => $this->motivo,
            'procesamiento' => $this->procesamiento,
            'consumidor' => $this->consumidor,
            'detalle' => $this->detalle,
            'recibido_en' => $this->recibido_en?->format('Y-m-d H:i:s.v'),
            'procesado_en' => $this->procesado_en?->format('Y-m-d H:i:s.v'),
        ];
    }
}
