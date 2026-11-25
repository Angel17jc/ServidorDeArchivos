<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Comprobantes de pago verificados por su consumidor, a la espera de que el backend (L3) confirme el pago
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes', function (Blueprint $table) {
            $table->id();
            $table->string('storage_key', 120)->unique();
            $table->string('referencia', 60)->nullable();   // pedido al que pertenece
            $table->string('correlation_id', 100)->index();
            $table->string('version_pdf', 8);
            $table->unsignedInteger('bytes');
            $table->char('sha256', 64);
            $table->string('estado', 20)->default('por_revisar');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes');
    }
};
