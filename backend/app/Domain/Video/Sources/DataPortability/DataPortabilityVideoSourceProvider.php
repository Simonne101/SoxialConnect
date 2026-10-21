<?php

namespace App\Domain\Video\Sources\DataPortability;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Sources\AcquisitionStep;
use App\Domain\Video\Sources\VideoSourceProvider;
use App\Models\VideoSource;

/**
 * Export officiel Google (Data Portability API) des vidéos YouTube DU PROPRIÉTAIRE (ADR-049, ADR-054) :
 * seule voie automatique autorisée pour obtenir le fichier d'une vidéo depuis son lien.
 *
 * 1. vidéo de la chaîne YouTube connectée par l'utilisateur (propriété vérifiée par videos.list) ;
 * 2. consentement Data Portability de ce compte (DataPortabilityConsents ; sinon CONSENT_REQUIRED et
 *    l'interface propose « Autoriser l'export Google ») ;
 * 3. export des groupes de ressources accordés (initiate), suivi par relances espacées (state) ;
 * 4. archive prête : téléchargement puis extraction de LA vidéo (DataPortabilityArchiveExtractor),
 *    importée ensuite dans la médiathèque par VideoSourceService.
 * Limites Google : comptes de l'EEE, de Suisse et du Royaume-Uni ; application en « Testing » :
 * 100 utilisateurs de test, autorisation de 7 jours ; publication : vérification + évaluation CASA.
 * Un export peut durer jusqu'à 7 jours (documentation Google) : l'attente suit cette échéance
 * (« deadline » dans les métadonnées), à intervalle croissant, et non un nombre fixe de relances.
 * Export en échec : une relance (RetryPortabilityArchive). Accès « une seule fois » déjà utilisé :
 * réinitialisation de l'autorisation, jeton local expiré, nouveau consentement demandé.
 */
class DataPortabilityVideoSourceProvider implements VideoSourceProvider
{
    /** Durée maximale d'un export selon la documentation Google (« The maximum duration is seven days »). */
    public const MAX_EXPORT_SECONDS = 7 * 86_400;

    public function __construct(
        private readonly DataPortabilityClient $client,
        private readonly DataPortabilityConsents $consents,
        private readonly DataPortabilityOAuth $oauth,
        private readonly DataPortabilityArchiveExtractor $extractor,
    ) {}

    public function name(): string
    {
        return 'data_portability';
    }

    public function method(): AcquisitionMethod
    {
        return AcquisitionMethod::DataPortability;
    }

    public function isConfigured(): bool
    {
        return $this->oauth->isConfigured();
    }

    /** Uniquement les vidéos d'une chaîne connectée par l'utilisateur (propriété vérifiée). */
    public function supports(VideoSource $source): bool
    {
        return $source->ownership_status === SourceOwnership::Owned;
    }

    public function begin(VideoSource $source): AcquisitionStep
    {
        try {
            $token = $this->token($source);
        } catch (OAuthException $e) {
            return AcquisitionStep::failed($e->errorCode, $e->getMessage(), $e->transient, 60);
        }
        $resources = $source->user !== null ? $this->consents->grantedResources($source->user, $source->ownership_account_id) : [];
        if ($token === null || $resources === []) {
            return $this->consentRequired();
        }
        try {
            $jobId = $this->client->initiate($token, $resources);
        } catch (DataPortabilityException $e) {
            return $this->failure($e, $source, $token);
        }

        return AcquisitionStep::waiting($this->pollSeconds(), [
            'archive_job_id' => $jobId,
            'initiated_at' => now()->toIso8601String(),
            'deadline' => now()->addSeconds(self::MAX_EXPORT_SECONDS)->toIso8601String(),
            'retried' => false,
        ]);
    }

    public function poll(VideoSource $source): AcquisitionStep
    {
        $jobId = $source->metadata['archive_job_id'] ?? null;
        if (! is_string($jobId)) {
            return $this->begin($source);
        }
        try {
            $token = $this->token($source);
        } catch (OAuthException $e) {
            return AcquisitionStep::failed($e->errorCode, $e->getMessage(), $e->transient, 60);
        }
        if ($token === null) {
            return $this->consentRequired();
        }
        try {
            $state = $this->client->state($token, $jobId);
        } catch (DataPortabilityException $e) {
            return $this->failure($e, $source, $token);
        }

        return match ($state['state']) {
            'IN_PROGRESS' => AcquisitionStep::waiting($this->pollSeconds($source), ['archive_job_id' => $jobId]),
            'FAILED' => $this->retryOnce($source, $token, $jobId),
            'CANCELLED' => AcquisitionStep::failed('EXPORT_FAILED', 'L\'export Google de la chaîne a été annulé.'),
            default => $this->extractor->extract($source, $state['urls']),
        };
    }

    /** Export en échec : une seule relance officielle (RetryPortabilityArchive), puis échec explicite. */
    private function retryOnce(VideoSource $source, string $token, string $jobId): AcquisitionStep
    {
        if (($source->metadata['retried'] ?? false) === true) {
            return AcquisitionStep::failed('EXPORT_FAILED', 'L\'export Google de la chaîne a échoué, y compris après une nouvelle tentative.');
        }
        try {
            $retried = $this->client->retry($token, $jobId);
        } catch (DataPortabilityException $e) {
            return $this->failure($e, $source, $token);
        }

        return AcquisitionStep::waiting($this->pollSeconds($source), ['archive_job_id' => $retried, 'retried' => true]);
    }

    private function token(VideoSource $source): ?string
    {
        $user = $source->user;

        return $user !== null ? $this->consents->accessTokenFor($user, $source->ownership_account_id) : null;
    }

    private function consentRequired(): AcquisitionStep
    {
        return AcquisitionStep::unavailable('CONSENT_REQUIRED', 'Autorisez l\'export officiel Google de votre chaîne : SoxialConnect pourra alors récupérer cette vidéo et générer les clips sans import.');
    }

    private function failure(DataPortabilityException $e, ?VideoSource $source = null, ?string $token = null): AcquisitionStep
    {
        if ($e->errorCode === 'CONSENT_EXHAUSTED') {
            // Accès « une seule fois » déjà utilisé : Google exige ResetAuthorization puis un nouveau consentement.
            if ($token !== null) {
                $this->client->resetAuthorization($token);
            }
            if ($source?->user !== null) {
                $this->consents->expire($source->user, $source->ownership_account_id);
            }

            return AcquisitionStep::unavailable('CONSENT_REQUIRED', 'L\'autorisation d\'export Google a déjà servi pour un export précédent : autorisez-la à nouveau (choisir « 30 jours » ou « 180 jours » sur l\'écran Google évite de la redonner à chaque vidéo).');
        }

        return $e->errorCode === 'CONSENT_REQUIRED'
            ? $this->consentRequired()
            : AcquisitionStep::failed($e->errorCode, $e->getMessage(), $e->transient, $this->pollSeconds($source));
    }

    /**
     * Intervalle de suivi croissant selon l'ancienneté de l'export : réglage (5 min par défaut) la
     * première heure, 15 min ensuite, 30 min au-delà d'une journée.
     */
    private function pollSeconds(?VideoSource $source = null): int
    {
        $base = max(30, (int) config('video.sources.data_portability.poll_seconds', 300));
        $started = $source?->metadata['initiated_at'] ?? null;
        $elapsed = is_string($started) ? max(0, now()->getTimestamp() - strtotime($started)) : 0;

        return match (true) {
            $elapsed >= 86_400 => max($base, 1_800),
            $elapsed >= 3_600 => max($base, 900),
            default => $base,
        };
    }
}
