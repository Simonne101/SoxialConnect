<?php

namespace App\Jobs;

use App\Domain\Analytics\AnalyticsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Collecte des statistiques d'un compte (ADR-063). Une seule exécution ($tries = 1) : les nouvelles tentatives
 * sont différées et bornées par AnalyticsSyncService (état next_attempt_at), jamais par la file. Unique dans la
 * file : un compte n'est jamais en attente deux fois.
 */
class SyncAccountMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $socialAccountId)
    {
        $this->onQueue((string) config('analytics.sync.queue', 'analytics'));
    }

    public function uniqueId(): string
    {
        return 'account:'.$this->socialAccountId;
    }

    public function uniqueFor(): int
    {
        return (int) config('analytics.sync.lock_seconds', 600);
    }

    public function handle(AnalyticsSyncService $sync): void
    {
        $sync->syncAccount($this->socialAccountId);
    }
}
