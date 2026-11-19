<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Metadatos de un archivo guardado en el Nodo L5. El binario vive en L5; aquí solo su referencia y su propietario. */
#[Fillable(['user_id', 'nombre_original', 'storage_key', 'mime_type', 'size'])]
class Archivo extends Model
{
    public function propietario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Representación pública: nunca incluye datos del usuario más allá de su id y nombre. */
    public function aRespuesta(): array
    {
        return [
            'id' => $this->id,
            'nombre_original' => $this->nombre_original,
            'storage_key' => $this->storage_key,
            'url' => '/files/'.$this->storage_key,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'propietario' => ['id' => $this->propietario->id, 'nombre' => $this->propietario->name],
            'creado_en' => $this->created_at?->toIso8601String(),
        ];
    }
}
