<?php

namespace App\Jobs;

use App\Domain\Publishing\PublishingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Suivi d'une publication asynchrone (docs/publishing.md §4 et §12) : vérifie une fois le statut
 * d'une tentative « en traitement » (external_post_id = identifiant de suivi), puis conclut ou
 * programme la vérification suivante. Générique : aucune logique propre à un réseau.
 *
 * - $trackingId sert de jeton : si la tentative a changé ou est déjà conclue, le job ne fait rien ;
 * - une seule exécution ($tries = 1) ; un job perdu est rattrapé par publishing:dispatch-due.
 */
class ReconcilePublicationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Un appel de statut, jamais d'envoi de fichier. Inférieur à retry_after (QueueSafetyTest). */
    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $attemptId,
        public readonly string $variantId,
        public readonly string $trackingId,
        public readonly int $check = 1,
    ) {
        $this->onQueue('publishing');
    }

    public function handle(PublishingService $publishing): void
    {
        $publishing->reconcile($this->attemptId, $this->variantId, $this->trackingId, $this->check);
    }
}
