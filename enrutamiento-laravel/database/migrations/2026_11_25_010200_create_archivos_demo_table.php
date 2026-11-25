<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Archivos ficticios que demo:preparar sube al Nodo L5 para las pruebas (y que demo:limpiar borra)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archivos_demo', function (Blueprint $table) {
            $table->id();
            $table->string('rol', 30)->unique();        // imagen | comprobante | eliminar
            $table->string('descripcion', 80);
            $table->string('storage_key', 120);
            $table->string('mime_type', 40);
            $table->unsignedInteger('bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archivos_demo');
    }
};
