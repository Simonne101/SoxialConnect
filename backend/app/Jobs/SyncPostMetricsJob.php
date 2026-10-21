<?php

namespace App\Jobs;

use App\Domain\Analytics\AnalyticsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Collecte des statistiques d'une publication (ADR-063) : mêmes règles que SyncAccountMetricsJob. */
class SyncPostMetricsJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public readonly string $postVariantId)
    {
        $this->onQueue((string) config('analytics.sync.queue', 'analytics'));
    }

    public function uniqueId(): string
    {
        return 'post:'.$this->postVariantId;
    }

    public function uniqueFor(): int
    {
        return (int) config('analytics.sync.lock_seconds', 600);
    }

    public function handle(AnalyticsSyncService $sync): void
    {
        $sync->syncPost($this->postVariantId);
    }
}
