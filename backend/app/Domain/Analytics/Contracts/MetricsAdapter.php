<?php

namespace App\Domain\Analytics\Contracts;

use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use App\Domain\Analytics\Exceptions\MetricsNotSupported;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;

/**
 * Adaptateur de collecte des statistiques d'un réseau (un par plateforme, config/analytics.php → adapters).
 * Règles : jetons obtenus par les gestionnaires existants (Domain/Social/Tokens) ; métrique non fournie = null,
 * jamais 0 ; réponse brute expurgée de tout jeton ou donnée personnelle avant d'être renvoyée.
 */
interface MetricsAdapter
{
    /**
     * Statistiques du compte sur la période [from, to] (jours dans le fuseau de référence de la plateforme).
     *
     * @throws MetricsNotSupported métriques de compte non accessibles pour ce compte
     * @throws MetricsFetchFailed échec de l'appel (temporaire ou définitif)
     */
    public function fetchAccountMetrics(SocialAccount $account, CarbonImmutable $from, CarbonImmutable $to): MetricsResult;

    /**
     * Statistiques d'une publication (external_post_id renseigné).
     *
     * @throws MetricsNotSupported
     * @throws MetricsFetchFailed
     */
    public function fetchPostMetrics(SocialAccount $account, PostVariant $variant): MetricsResult;
}
