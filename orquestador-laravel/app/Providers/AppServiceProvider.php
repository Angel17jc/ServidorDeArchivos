<?php

namespace App\Providers;

use App\Services\ClienteDependencias;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El tiempo máximo de cada consulta sale de config/orquestacion.php (3 s)
        $this->app->bind(ClienteDependencias::class, fn () => new ClienteDependencias((int) config('orquestacion.timeout', 3)));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
