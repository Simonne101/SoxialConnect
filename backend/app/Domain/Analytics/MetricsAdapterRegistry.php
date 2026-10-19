<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Contracts\MetricsAdapter;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Adaptateur de collecte d'un réseau (config/analytics.php → adapters). Réseau sans adaptateur : null — la
 * collecte est marquée « unsupported », rien n'est simulé.
 */
final class MetricsAdapterRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(?string $platformKey): ?MetricsAdapter
    {
        $class = $platformKey ? config("analytics.adapters.{$platformKey}") : null;
        if (! $class) {
            return null;
        }
        $adapter = $this->container->make($class);
        if (! $adapter instanceof MetricsAdapter) {
            throw new InvalidArgumentException("L'adaptateur de statistiques de {$platformKey} doit implémenter MetricsAdapter.");
        }

        return $adapter;
    }

    /** @return list<string> clés des plateformes dotées d'un adaptateur */
    public function platforms(): array
    {
        return array_keys(array_filter((array) config('analytics.adapters', [])));
    }
}
