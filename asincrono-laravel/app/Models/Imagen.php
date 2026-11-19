<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Imagen de un producto guardada en el Nodo L5. */
#[Fillable(['producto', 'storage_key', 'mime_type', 'size'])]
class Imagen extends Model
{
    protected $table = 'imagenes';
}
