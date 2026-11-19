<?php

namespace App\Providers;

use App\Mensajeria\PublicadorMensajes;
use App\Mensajeria\PublicadorRabbit;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El productor publica en RabbitMQ; en las pruebas se sustituye por un publicador en memoria
        $this->app->bind(PublicadorMensajes::class, PublicadorRabbit::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
