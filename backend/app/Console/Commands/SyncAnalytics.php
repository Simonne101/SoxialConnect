<?php

namespace App\Console\Commands;

use App\Domain\Analytics\AnalyticsSyncService;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

/**
 * Collecte des statistiques (ADR-063). Planifiée : envoie les collectes dues (jamais collectées, intervalle
 * minimal écoulé, nouvelle tentative échue). --account : synchronisation manuelle d'un compte. Sans adaptateur
 * déclaré (config/analytics.php), rien n'est envoyé.
 */
class SyncAnalytics extends Command
{
    protected $signature = 'analytics:sync {--account= : identifiant du compte social à synchroniser maintenant}';

    protected $description = 'Envoie les collectes de statistiques dues (ou celle d\'un compte) vers la file analytics';

    public function handle(AnalyticsSyncService $sync): int
    {
        if ($id = $this->option('account')) {
            $account = SocialAccount::query()->with('platform')->find($id);
            if ($account === null) {
                $this->components->error('Compte social introuvable.');

                return self::FAILURE;
            }
            if (! $account->isConnected() || ! $sync->supports($account)) {
                $this->components->warn('Collecte impossible pour ce compte (non connecté ou réseau sans collecte de statistiques).');

                return self::FAILURE;
            }
            $this->components->info($sync->dispatchManual($account).' collecte(s) envoyée(s).');

            return self::SUCCESS;
        }
        $this->components->info($sync->dispatchDue().' collecte(s) due(s) envoyée(s).');

        return self::SUCCESS;
    }
}
