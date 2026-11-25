<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Constancia de cada mensaje recibido: la decisión de validación, filtro o enrutamiento
// y, si se entregó, qué consumidor lo procesó y con qué resultado.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id')->unique();
            $table->string('correlation_id', 100)->index();
            $table->string('evento_id', 100)->nullable();
            $table->string('tipo', 60)->nullable();
            $table->string('prioridad', 10)->nullable();
            $table->string('estado_operacion', 30)->nullable();
            $table->json('datos')->nullable();
            $table->string('resultado', 20);            // enrutado | no_enrutable | filtrado | invalido | fallo_transporte
            $table->string('regla', 4)->nullable();     // R1–R4 del enrutador, F1–F2 del filtro, V de validación
            $table->string('destino', 20)->nullable();  // miniaturas | comprobantes | limpieza | no_enrutables
            $table->string('motivo', 300)->nullable();
            $table->string('procesamiento', 12)->nullable(); // pendiente → procesado | error (solo si se publicó)
            $table->string('consumidor', 40)->nullable();
            $table->string('detalle', 300)->nullable();
            $table->string('archivo_generado', 120)->nullable();
            $table->timestamp('recibido_en', 3)->nullable();
            $table->timestamp('procesado_en', 3)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventos');
    }
};
