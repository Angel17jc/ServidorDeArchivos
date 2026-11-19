<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Estado de cada solicitud de miniatura: permite seguir el mensaje por message_id y la operación por correlation_id
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procesamientos', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('correlation_id', 100)->index();
            $table->string('modo', 10);                    // asincrono | sincrono
            $table->foreignId('imagen_id')->constrained('imagenes');
            $table->unsignedSmallInteger('ancho');
            $table->string('estado', 12)->default('pendiente'); // pendiente → procesando → completado | error
            $table->string('miniatura_key', 120)->nullable();
            $table->string('error')->nullable();
            $table->unsignedTinyInteger('intentos')->default(0);
            $table->timestamp('publicado_en', 3)->nullable();
            $table->timestamp('iniciado_en', 3)->nullable();
            $table->timestamp('completado_en', 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('procesamientos');
    }
};
