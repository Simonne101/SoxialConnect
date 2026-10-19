<?php

namespace App\Console\Commands;

use App\Models\AnalyticsSnapshot;
use Illuminate\Console\Command;

/**
 * Conservation (docs/database.md §3.7, ADR-063) : réponses brutes des plateformes (analytics_snapshots)
 * supprimées après analytics.retention.raw_months (13 mois) ; les métriques normalisées (analytics) sont
 * conservées — leur lien vers la réponse brute passe à NULL (clé étrangère ON DELETE SET NULL).
 */
class PurgeAnalyticsSnapshots extends Command
{
    protected $signature = 'analytics:purge';

    protected $description = 'Supprime les réponses brutes des statistiques au-delà de la durée de conservation';

    public function handle(): int
    {
        $months = max(1, (int) config('analytics.retention.raw_months', 13));
        $limit = now()->subMonthsNoOverflow($months);
        $deleted = 0;
        AnalyticsSnapshot::query()->where('fetched_at', '<', $limit)->select('id')
            ->chunkById(500, function ($chunk) use (&$deleted) {
                $deleted += AnalyticsSnapshot::query()->whereKey($chunk->modelKeys())->delete();
            });
        $this->components->info("{$deleted} réponse(s) brute(s) de plus de {$months} mois supprimée(s) ; métriques normalisées conservées.");

        return self::SUCCESS;
    }
}
