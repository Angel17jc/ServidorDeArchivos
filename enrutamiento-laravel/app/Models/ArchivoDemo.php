<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Archivo ficticio subido al Nodo L5 para las pruebas. */
class ArchivoDemo extends Model
{
    protected $table = 'archivos_demo';

    protected $fillable = ['rol', 'descripcion', 'storage_key', 'mime_type', 'bytes'];
}
