<?php

namespace App\Domain\Analytics\Adapters;

use App\Domain\Analytics\Contracts\MetricsAdapter;
use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Enums\Granularity;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use App\Domain\Analytics\Exceptions\MetricsNotSupported;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\YouTubeTokenManager;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Statistiques YouTube (étape 7, P2 — ADR-064) via la YouTube Data API v3, en lecture seule (scope
 * youtube.readonly déjà accordé) : aucune publication, modification ni suppression.
 *
 * - Publication : videos.list (part statistics + snippet.channelId) → vues, mentions J'aime, commentaires.
 * - Compte : channels.list?mine=true → abonnés, vues cumulées de la chaîne (nombre de vidéos dans extra).
 *
 * La Data API ne fournit que des cumuls : un point « lifetime » daté du jour de collecte (une collecte par jour
 * forme la série). Les séries journalières exigeraient l'API YouTube Analytics (scope yt-analytics.readonly,
 * non demandé). Un compteur masqué ou absent (J'aime masqués, commentaires désactivés, abonnés masqués) reste
 * null, jamais 0. La vidéo ou la chaîne doit appartenir à la chaîne connectée (external_account_id).
 * Le jeton vient de YouTubeTokenManager ; il n'apparaît ni dans la réponse conservée ni dans les messages.
 */
class YouTubeMetricsAdapter implements MetricsAdapter
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3';

    private const API_VERSION = 'youtube-data-v3';

    private const TIMEOUT_SECONDS = 20;

    public function __construct(private readonly YouTubeTokenManager $tokens) {}

    public function fetchAccountMetrics(SocialAccount $account, CarbonImmutable $from, CarbonImmutable $to): MetricsResult
    {
        $json = $this->get($account, '/channels', ['part' => 'statistics', 'mine' => 'true', 'fields' => 'items(id,statistics)']);
        $channel = $json['items'][0] ?? null;
        if (! is_array($channel)) {
            throw new MetricsNotSupported('CHANNEL_REQUIRED', 'Ce compte Google n\'a pas de chaîne YouTube active : aucune statistique de chaîne à collecter.');
        }
        $this->assertSameChannel($account, $channel['id'] ?? null, 'CHANNEL_MISMATCH', 'L\'autorisation YouTube ne correspond plus à la chaîne connectée. Reconnectez le compte.');

        $stats = (array) ($channel['statistics'] ?? []);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new MetricsResult(
            payload: ['endpoint' => 'channels.list', 'response' => $json],
            points: [MetricsResult::point($today->toDateString(), [
                'followers' => ($stats['hiddenSubscriberCount'] ?? false) === true ? null : ($stats['subscriberCount'] ?? null),
                'views' => $stats['viewCount'] ?? null,
                'video_count' => $stats['videoCount'] ?? null,
            ], Granularity::Lifetime)],
            periodStart: $today,
            periodEnd: $today,
            apiVersion: self::API_VERSION,
        );
    }

    public function fetchPostMetrics(SocialAccount $account, PostVariant $variant): MetricsResult
    {
        if ($variant->social_account_id !== $account->getKey()) {
            throw new MetricsNotSupported('ACCOUNT_MISMATCH', 'Cette publication n\'appartient pas à ce compte.');
        }
        $videoId = (string) $variant->external_post_id;
        $json = $this->get($account, '/videos', ['part' => 'statistics,snippet', 'id' => $videoId, 'fields' => 'items(id,snippet(channelId),statistics)']);
        $video = collect($json['items'] ?? [])->first(fn ($item) => is_array($item) && ($item['id'] ?? null) === $videoId);
        if ($video === null) {
            throw new MetricsNotSupported('VIDEO_NOT_FOUND', 'Vidéo introuvable sur YouTube (supprimée ou devenue inaccessible) : aucune statistique à collecter.');
        }
        $this->assertSameChannel($account, $video['snippet']['channelId'] ?? null, 'VIDEO_NOT_OWNED', 'Cette vidéo n\'appartient pas à la chaîne YouTube connectée.');

        $stats = (array) ($video['statistics'] ?? []);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new MetricsResult(
            payload: ['endpoint' => 'videos.list', 'response' => $json],
            points: [MetricsResult::point($today->toDateString(), [
                'views' => $stats['viewCount'] ?? null,
                'likes' => $stats['likeCount'] ?? null, // absent si la chaîne masque les J'aime
                'comments' => $stats['commentCount'] ?? null, // absent si les commentaires sont désactivés
            ], Granularity::Lifetime)],
            periodStart: $variant->published_at !== null ? CarbonImmutable::parse($variant->published_at)->utc()->startOfDay() : $today,
            periodEnd: $today,
            apiVersion: self::API_VERSION,
        );
    }

    /**
     * Appel GET authentifié. 401 malgré un jeton valide en apparence : un seul renouvellement forcé puis un seul
     * nouvel essai (même règle que la publication).
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function get(SocialAccount $account, string $path, array $query): array
    {
        try {
            $response = $this->call($this->tokens->accessTokenFor($account), $path, $query);
            if ($response->status() === 401) {
                $response = $this->call($this->tokens->accessTokenFor($account, forceRefresh: true), $path, $query);
            }
        } catch (OAuthException $e) {
            // Refresh refusé : le gestionnaire a déjà marqué le compte « à reconnecter ».
            throw new MetricsFetchFailed($e->errorCode, $e->getMessage(), transient: $e->transient);
        } catch (ConnectionException) {
            throw new MetricsFetchFailed('NETWORK_ERROR', 'YouTube est momentanément injoignable. Nouvel essai automatique.', transient: true);
        }
        if (! $response->successful()) {
            throw $this->fromApiError($response);
        }
        $json = $response->json();
        if (! is_array($json) || (isset($json['items']) && ! is_array($json['items']))) {
            throw new MetricsFetchFailed('INVALID_RESPONSE', 'Réponse YouTube inattendue. Nouvel essai automatique.', transient: true);
        }

        return $json;
    }

    /** @param array<string, string> $query */
    private function call(string $accessToken, string $path, array $query): Response
    {
        return Http::withToken($accessToken)->acceptJson()
            ->connectTimeout(10)->timeout(self::TIMEOUT_SECONDS)
            ->get(self::API_URL.$path, $query);
    }

    private function assertSameChannel(SocialAccount $account, mixed $channelId, string $code, string $message): void
    {
        if (blank($account->external_account_id) || $channelId !== $account->external_account_id) {
            throw new MetricsNotSupported($code, $message);
        }
    }

    /** Erreurs Google (error.errors[0].reason) — ni jeton ni corps brut repris dans le message. */
    private function fromApiError(Response $response): MetricsFetchFailed|MetricsNotSupported
    {
        $status = $response->status();
        $reason = (string) ($response->json('error.errors.0.reason') ?? $response->json('error.status') ?? '');

        return match (true) {
            in_array($reason, ['quotaExceeded', 'dailyLimitExceeded'], true) => new MetricsFetchFailed('QUOTA_EXCEEDED', 'Quota quotidien de l\'API YouTube atteint. Nouvel essai après son renouvellement (minuit, heure du Pacifique).', true, $this->secondsUntilQuotaReset()),
            $status === 429 || in_array($reason, ['rateLimitExceeded', 'userRateLimitExceeded'], true) => new MetricsFetchFailed('RATE_LIMITED', 'Trop de requêtes vers YouTube. Nouvel essai automatique.', true, $this->retryAfter($response)),
            $status === 401 => new MetricsFetchFailed('TOKEN_INVALID', 'YouTube a refusé l\'autorisation. Reconnectez le compte puis relancez la synchronisation.'),
            $reason === 'youtubeSignupRequired' => new MetricsNotSupported('CHANNEL_REQUIRED', 'Ce compte Google n\'a pas de chaîne YouTube active.'),
            $status === 403 => new MetricsFetchFailed('PERMISSION_DENIED', 'Autorisation YouTube insuffisante pour lire les statistiques. Reconnectez le compte en acceptant toutes les autorisations, puis relancez la synchronisation.'),
            $status === 404 => new MetricsNotSupported('VIDEO_NOT_FOUND', 'Vidéo ou chaîne introuvable sur YouTube : aucune statistique à collecter.'),
            $status >= 500 => new MetricsFetchFailed('PLATFORM_UNAVAILABLE', 'YouTube rencontre un problème temporaire. Nouvel essai automatique.', true),
            $status === 400 => new MetricsFetchFailed('INVALID_REQUEST', 'YouTube a refusé la demande de statistiques ('.($reason ?: 'requête invalide').').'),
            default => new MetricsFetchFailed('HTTP_ERROR', "YouTube a répondu par une erreur ({$status})."),
        };
    }

    /** Le quota de la Data API se renouvelle à minuit, heure du Pacifique. */
    private function secondsUntilQuotaReset(): int
    {
        $now = CarbonImmutable::now('America/Los_Angeles');

        return (int) max(60, $now->diffInSeconds($now->addDay()->startOfDay()));
    }

    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');

        return is_numeric($header) ? max(1, (int) $header) : null;
    }
}
