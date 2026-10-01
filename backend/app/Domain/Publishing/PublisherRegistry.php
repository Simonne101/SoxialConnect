<?php

namespace App\Domain\Publishing;

use App\Domain\Publishing\Adapters\NotConfiguredPublisher;
use App\Domain\Publishing\Contracts\SocialPublisher;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Choix de l'adaptateur d'un réseau (config/publishing.php → adapters). Tout réseau sans
 * adaptateur déclaré utilise NotConfiguredPublisher : rien n'est envoyé, rien n'est simulé.
 */
final class PublisherRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(?string $platformKey): SocialPublisher
    {
        $class = $platformKey ? config("publishing.adapters.{$platformKey}") : null;
        if (! $class) {
            return $this->container->make(NotConfiguredPublisher::class);
        }

        $adapter = $this->container->make($class);
        if (! $adapter instanceof SocialPublisher) {
            throw new InvalidArgumentException("L'adaptateur de {$platformKey} doit implémenter SocialPublisher.");
        }

        return $adapter;
    }
}
