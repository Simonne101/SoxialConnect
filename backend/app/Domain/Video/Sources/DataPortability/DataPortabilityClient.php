<?php

namespace App\Domain\Video\Sources\DataPortability;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Client de la Google Data Portability API (référence REST v1, vérifiée le 2026-10-07 et le 2026-10-12) :
 * - POST /v1/portabilityArchive:initiate  { resources[] }  → { archiveJobId, accessType } ;
 * - GET  /v1/archiveJobs/{id}/portabilityArchiveState      → { state, urls[] } (urls si COMPLETE) ;
 * - POST /v1/archiveJobs/{id}:retry                         → { archiveJobId } (export en échec) ;
 * - POST /v1/authorization:reset                           → {} (révoque les scopes accordés ; exigé avant
 *   de réexporter une ressource après un accès « une seule fois »).
 * Accès « une seule fois » déjà utilisé : 429 RESOURCE_EXHAUSTED « already been exported » →
 * CONSENT_EXHAUSTED (pas une limite passagère : réinitialisation + nouveau consentement).
 * Jeton OAuth de l'utilisateur (scope dataportability.*) passé à chaque appel, jamais journalisé.
 * Utilisable seulement après approbation de l'application par Google (scopes restreints, CASA).
 */
class DataPortabilityClient
{
    public const STATES = ['IN_PROGRESS', 'COMPLETE', 'FAILED', 'CANCELLED'];

    /**
     * @param  list<string>  $resources  groupes de ressources (1:1 avec les scopes), ex. youtube.public_videos
     *
     * @throws DataPortabilityException
     */
    public function initiate(string $accessToken, array $resources): string
    {
        $body = $this->send(fn () => $this->http($accessToken)->post($this->url('/v1/portabilityArchive:initiate'), ['resources' => array_values($resources)]));
        $jobId = $body['archiveJobId'] ?? null;
        if (! is_string($jobId) || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $jobId)) {
            throw new DataPortabilityException('INVALID_RESPONSE', 'Réponse inattendue de l\'export Google.');
        }

        return $jobId;
    }

    /**
     * Relance d'un export en échec (RetryPortabilityArchive). Renvoie l'identifiant de l'export relancé.
     *
     * @throws DataPortabilityException
     */
    public function retry(string $accessToken, string $archiveJobId): string
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $archiveJobId)) {
            throw new DataPortabilityException('INVALID_REQUEST', 'Identifiant d\'export invalide.');
        }
        $body = $this->send(fn () => $this->http($accessToken)->post($this->url("/v1/archiveJobs/{$archiveJobId}:retry"), (object) []));
        $jobId = $body['archiveJobId'] ?? $archiveJobId;

        return is_string($jobId) && preg_match('/^[A-Za-z0-9_-]{1,128}$/', $jobId) ? $jobId : $archiveJobId;
    }

    /**
     * Réinitialise l'autorisation (ResetAuthorization) : révoque les scopes accordés à l'application, ce qui
     * permet un nouvel export des mêmes ressources après un nouveau consentement. Au mieux (sans exception).
     */
    public function resetAuthorization(string $accessToken): bool
    {
        try {
            $this->send(fn () => $this->http($accessToken)->post($this->url('/v1/authorization:reset'), (object) []));

            return true;
        } catch (DataPortabilityException) {
            return false;
        }
    }

    /**
     * @return array{state: string, urls: list<string>}
     *
     * @throws DataPortabilityException
     */
    public function state(string $accessToken, string $archiveJobId): array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $archiveJobId)) {
            throw new DataPortabilityException('INVALID_REQUEST', 'Identifiant d\'export invalide.');
        }
        $body = $this->send(fn () => $this->http($accessToken)->get($this->url("/v1/archiveJobs/{$archiveJobId}/portabilityArchiveState")));
        $state = $body['state'] ?? null;
        if (! is_string($state) || ! in_array($state, self::STATES, true)) {
            throw new DataPortabilityException('INVALID_RESPONSE', 'Réponse inattendue de l\'export Google.');
        }
        // URLs signées Cloud Storage uniquement (https, domaine Google) : rien d'autre n'est suivi.
        $urls = array_values(array_filter((array) ($body['urls'] ?? []), fn ($url) => is_string($url) && self::isSignedStorageUrl($url)));

        return ['state' => $state, 'urls' => $urls];
    }

    public static function isSignedStorageUrl(string $url): bool
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? null) === 'https' && ! isset($parts['port']) && ! isset($parts['user'])
            && in_array(strtolower($parts['host'] ?? ''), ['storage.googleapis.com'], true);
    }

    private function http(string $accessToken): PendingRequest
    {
        return Http::withToken($accessToken)->acceptJson()->asJson()->withoutRedirecting()
            ->connectTimeout(10)->timeout((int) config('video.sources.data_portability.timeout', 30));
    }

    private function url(string $path): string
    {
        return rtrim((string) config('video.sources.data_portability.base_url'), '/').$path;
    }

    /**
     * @param  callable(): Response  $call
     * @return array<string, mixed>
     *
     * @throws DataPortabilityException
     */
    private function send(callable $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new DataPortabilityException('NETWORK_ERROR', 'L\'export Google est momentanément injoignable.', transient: true);
        }
        if ($response->successful()) {
            $body = $response->json();

            return is_array($body) ? $body : throw new DataPortabilityException('INVALID_RESPONSE', 'Réponse inattendue de l\'export Google.', httpStatus: $response->status());
        }

        $status = $response->status();
        // Seul le statut et une mention connue sont lus, jamais le message complet n'est transmis.
        $exhausted = $status === 429 && str_contains(strtolower((string) $response->json('error.message')), 'already been exported');
        throw match (true) {
            $exhausted => new DataPortabilityException('CONSENT_EXHAUSTED', 'L\'autorisation d\'export Google a déjà servi : elle doit être accordée à nouveau.', httpStatus: $status),
            $status === 401 => new DataPortabilityException('CONSENT_REQUIRED', 'L\'autorisation d\'export Google a expiré ou a été retirée : elle doit être renouvelée.', httpStatus: $status),
            $status === 403 => new DataPortabilityException('PERMISSION_DENIED', 'Google refuse l\'export pour ce compte (région, type de compte ou autorisation).', httpStatus: $status),
            $status === 429 => new DataPortabilityException('RATE_LIMITED', 'Limite d\'export Google atteinte : nouvel essai plus tard.', transient: true, httpStatus: $status),
            $status >= 500 => new DataPortabilityException('PROVIDER_UNAVAILABLE', 'L\'export Google est momentanément indisponible.', transient: true, httpStatus: $status),
            default => new DataPortabilityException('INVALID_REQUEST', 'Demande d\'export refusée par Google.', httpStatus: $status),
        };
    }
}
