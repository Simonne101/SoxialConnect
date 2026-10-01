<?php

namespace App\Console\Commands;

use App\Domain\Publishing\PublishingService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Planificateur de publication (chaque minute, routes/console.php) : met en file les variantes
 * programmées arrivées à échéance, par lots (SKIP LOCKED), reprend les envois bloqués et relance
 * le suivi des publications asynchrones restées sans vérification (docs/publishing.md §3 et §4).
 * Aucun appel à un réseau social ici : l'envoi et le suivi sont faits par les jobs.
 */
#[Signature('publishing:dispatch-due {--max-batches=20 : Nombre maximal de lots par passage}')]
#[Description('Met en file les publications programmées arrivées à échéance')]
class DispatchDuePublications extends Command
{
    public function handle(PublishingService $publishing): int
    {
        $batch = (int) config('publishing.dispatch_batch_size', 50);
        $queued = 0;
        for ($i = 0; $i < max(1, (int) $this->option('max-batches')); $i++) {
            $count = $publishing->dispatchDue($batch);
            $queued += $count;
            if ($count === 0) {
                break;
            }
        }

        $recovered = $publishing->recoverStale();
        $tracked = $publishing->sweepProcessing();

        $this->components->info("Variantes mises en file : {$queued} ; envois bloqués passés en échec : {$recovered['abandoned']} ; jobs renvoyés : {$recovered['redispatched']} ; suivis relancés : {$tracked}.");

        return self::SUCCESS;
    }
}
