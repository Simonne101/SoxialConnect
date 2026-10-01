<?php

namespace App\Domain\Publishing\Contracts;

use App\Domain\Publishing\Data\PublishResult;
use App\Models\SocialAccount;

/**
 * Capacité optionnelle d'un adaptateur dont la publication est asynchrone (ex. TikTok) : après
 * un PublishResult::processing($trackingId), le moteur interroge le réseau jusqu'au résultat final
 * (ReconcilePublicationJob). Les adaptateurs synchrones (YouTube) ne l'implémentent pas.
 *
 * Résultats attendus :
 * - processing()        : toujours en cours → nouvelle vérification programmée ;
 * - success($postId)    : publié (identifiant définitif, ou le trackingId s'il n'y en a pas) ;
 * - permanentFailure()  : échec définitif signalé par le réseau ;
 * - temporaryFailure()  : impossible de vérifier pour l'instant (réseau, limite) → nouvelle vérification ;
 * - unknown()           : statut inexploitable → échec au résultat inconnu, jamais relancé automatiquement.
 */
interface TracksPublicationStatus
{
    public function checkStatus(SocialAccount $account, string $trackingId): PublishResult;
}
