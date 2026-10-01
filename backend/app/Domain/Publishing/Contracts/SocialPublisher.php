<?php

namespace App\Domain\Publishing\Contracts;

use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\Data\PublishResult;

/**
 * Contrat d'un adaptateur de publication (un par réseau, phase Social Connectors).
 * Règles :
 * - ne jamais renvoyer success sans identifiant de publication réel renvoyé par le réseau ;
 * - classer chaque erreur (temporaire / définitive) plutôt que lever une exception ;
 * - une exception signifie « résultat inconnu » : le moteur ne relance alors jamais à l'aveugle ;
 * - transmettre PublishRequest::idempotencyKey quand le réseau accepte une clé d'idempotence.
 */
interface SocialPublisher
{
    public function publish(PublishRequest $request): PublishResult;
}
