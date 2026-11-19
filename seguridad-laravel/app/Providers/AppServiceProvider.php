<?php

namespace App\Providers;

use App\Services\ClienteL5;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Credenciales del Nodo L5 desde config/services.php (variables de entorno)
        $this->app->bind(ClienteL5::class, fn () => new ClienteL5(
            (string) config('services.l5.url'),
            (string) config('services.l5.key'),
            (int) config('services.l5.timeout', 5),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // En producción todas las URL generadas usan HTTPS (el TLS lo termina NGINX en L1)
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
