<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Proxys de confiance lus dans la configuration (config/security.php → trusted_proxies, ADR-072) et non par
 * env() dans bootstrap/app.php : après « php artisan config:cache », env() n'y renvoie plus rien et l'application
 * ne reconnaîtrait plus le reverse proxy (IP clientes et HTTPS faussées). « * » n'est jamais accepté.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies()
    {
        return array_values(array_filter(
            (array) config('security.trusted_proxies', ['127.0.0.1', '::1']),
            fn ($ip) => is_string($ip) && $ip !== '' && $ip !== '*' && $ip !== '**',
        ));
    }
}
