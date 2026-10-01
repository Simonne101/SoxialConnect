<?php

namespace App\Jobs;

use App\Domain\Publishing\Enums\AttemptTrigger;
use App\Domain\Publishing\PublishingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Exécute une tentative de publication d'une variante (docs/publishing.md §4).
 *
 * - Une seule exécution par job ($tries = 1) : jamais de relance aveugle par la file. Les
 *   nouvelles tentatives après une erreur TEMPORAIRE sont décidées par le moteur (nouveau job
 *   différé, config publishing.backoff) ; NOT_CONFIGURED et les refus définitifs ne bouclent pas.
 * - $expectedAttempts sert de jeton : un job dupliqué ou périmé ne fait rien.
 * - Délai dépassé ou exception : failed() → la variante passe en échec (résultat inconnu si
 *   l'envoi avait commencé), elle ne reste jamais bloquée.
 */
class PublishPostVariantJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Laisse le temps d'envoyer une vidéo (YouTubePublisher : 270 s maximum pour le fichier).
     * Inférieur à queue.connections.*.retry_after = 360 s (tests/Feature/Queue/QueueSafetyTest).
     */
    public int $timeout = 300;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $variantId,
        public readonly string $trigger,
        public readonly ?string $triggeredBy,
        public readonly int $expectedAttempts,
        public readonly int $cycleAttempt = 1,
    ) {
        $this->onQueue('publishing');
    }

    public function handle(PublishingService $publishing): void
    {
        $publishing->execute(
            $this->variantId,
            AttemptTrigger::from($this->trigger),
            $this->triggeredBy,
            $this->expectedAttempts,
            $this->cycleAttempt,
            $this->job?->uuid(),
        );
    }

    public function failed(?Throwable $exception): void
    {
        app(PublishingService::class)->abandon($this->variantId, $this->expectedAttempts);
    }
}
