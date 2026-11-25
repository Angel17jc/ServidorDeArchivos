<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Comprobante de pago verificado por el consumidor de comprobantes. */
class Comprobante extends Model
{
    protected $fillable = ['storage_key', 'referencia', 'correlation_id', 'version_pdf', 'bytes', 'sha256', 'estado'];
}
