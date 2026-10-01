<?php

namespace App\Domain\Publishing\Adapters;

use App\Domain\Publishing\Contracts\SocialPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;

/**
 * Adaptateur par défaut tant qu'aucune intégration officielle (OAuth) n'est livrée :
 * aucun appel réseau, aucun token lu, et jamais de succès simulé.
 */
final class NotConfiguredPublisher implements SocialPublisher
{
    public const MESSAGE = 'La publication n\'a pas pu être envoyée : aucune intégration active n\'est disponible pour ce réseau. Rien n\'a été publié.';

    public function publish(PublishRequest $request): PublishResult
    {
        return PublishResult::notConfigured(self::MESSAGE);
    }
}
