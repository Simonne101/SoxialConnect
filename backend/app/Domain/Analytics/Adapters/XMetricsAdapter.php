<?php

namespace App\Domain\Analytics\Adapters;

use App\Domain\Analytics\Contracts\MetricsAdapter;
use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Enums\Granularity;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use App\Domain\Analytics\Exceptions\MetricsNotSupported;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\XTokenManager;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Statistiques X (étape 7, P3 — ADR-067), API v2 en lecture seule avec le jeton de l'utilisateur (scopes
 * tweet.read et users.read déjà accordés). Chaque lecture est facturée par X : l'adaptateur n'est déclaré
 * que si ANALYTICS_X_ENABLED=true (config/analytics.php).
 *
 * - Compte : GET /2/users/me (user.fields=public_metrics) → abonnés ; abonnements, posts, listes dans extra.
 * - Publication : GET /2/tweets/{id} (tweet.fields=public_metrics, author_id ; non_public_metrics en plus pour
 *   un post de moins de 30 jours, seule fenêtre où X les fournit à son auteur) → impressions, J'aime,
 *   réponses, reposts, signets ; clics de lien et engagements quand X les fournit ; citations dans extra.
 *
 * Cumuls uniquement : un point « lifetime » daté du jour de collecte. Valeur absente → null, jamais 0. Le post
 * doit avoir pour auteur le compte connecté (external_account_id). Jeton : XTokenManager ; jamais conservé.
 */
class XMetricsAdapter implements MetricsAdapter
{
    private const API_URL = 'https://api.x.com/2';

    private const API_VERSION = 'x-api-v2';

    /** X ne fournit les métriques non publiques que pour les posts de moins de 30 jours. */
    private const NON_PUBLIC_DAYS = 29;

    public function __construct(private readonly XTokenManager $tokens) {}

    public function fetchAccountMetrics(SocialAccount $account, CarbonImmutable $from, CarbonImmutable $to): MetricsResult
    {
        $json = $this->get($account, '/users/me', ['user.fields' => 'public_metrics']);
        $user = $json['data'] ?? null;
        if (! is_array($user) || ($user['id'] ?? null) !== $account->external_account_id || blank($account->external_account_id)) {
            throw new MetricsNotSupported('ACCOUNT_MISMATCH', 'L\'autorisation X ne correspond plus au compte connecté. Reconnectez le compte.');
        }
        $stats = (array) ($user['public_metrics'] ?? []);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new MetricsResult(
            payload: ['endpoint' => 'users/me', 'response' => $json],
            points: [MetricsResult::point($today->toDateString(), [
                'followers' => $stats['followers_count'] ?? null,
                'following_count' => $stats['following_count'] ?? null,
                'post_count' => $stats['tweet_count'] ?? null,
                'listed_count' => $stats['listed_count'] ?? null,
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
        $postId = (string) $variant->external_post_id;
        if (! ctype_digit($postId)) {
            throw new MetricsNotSupported('POST_NOT_FOUND', 'Identifiant de publication X invalide : aucune statistique à collecter.');
        }
        $publishedAt = $variant->published_at !== null ? CarbonImmutable::parse($variant->published_at)->utc() : null;
        $recent = $publishedAt !== null && $publishedAt->gte(CarbonImmutable::now('UTC')->subDays(self::NON_PUBLIC_DAYS));
        $fields = $recent ? 'public_metrics,non_public_metrics,author_id' : 'public_metrics,author_id';

        $json = $this->get($account, '/tweets/'.$postId, ['tweet.fields' => $fields]);
        $post = $json['data'] ?? null;
        if (! is_array($post) || ($post['id'] ?? null) !== $postId) {
            throw new MetricsNotSupported('POST_NOT_FOUND', 'Publication introuvable sur X (supprimée ou inaccessible) : aucune statistique à collecter.');
        }
        if (blank($account->external_account_id) || ($post['author_id'] ?? null) !== $account->external_account_id) {
            throw new MetricsNotSupported('POST_NOT_OWNED', 'Cette publication X n\'a pas été publiée par le compte connecté.');
        }

        $public = (array) ($post['public_metrics'] ?? []);
        $private = (array) ($post['non_public_metrics'] ?? []);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new MetricsResult(
            payload: ['endpoint' => 'tweets/:id', 'fields' => $fields, 'response' => $json],
            points: [MetricsResult::point($today->toDateString(), [
                'impressions' => $public['impression_count'] ?? null,
                'likes' => $public['like_count'] ?? null,
                'comments' => $public['reply_count'] ?? null,
                'shares' => $public['retweet_count'] ?? null,
                'saves' => $public['bookmark_count'] ?? null,
                'clicks' => $private['url_link_clicks'] ?? null,
                'engagements' => $private['engagements'] ?? null,
                'quotes' => $public['quote_count'] ?? null,
                'profile_clicks' => $private['user_profile_clicks'] ?? null,
            ], Granularity::Lifetime)],
            periodStart: $publishedAt?->startOfDay() ?? $today,
            periodEnd: $today,
            apiVersion: self::API_VERSION,
        );
    }

    /**
     * Appel GET authentifié. 401 : un seul renouvellement forcé puis un seul nouvel essai ; refusé encore →
     * compte « à reconnecter » (XTokenManager::rejected), échec définitif.
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
            throw new MetricsFetchFailed($e->errorCode, $e->getMessage(), transient: $e->transient);
        } catch (ConnectionException) {
            throw new MetricsFetchFailed('NETWORK_ERROR', 'X est momentanément injoignable. Nouvel essai automatique.', transient: true);
        }
        if (! $response->successful()) {
            throw $this->fromApiError($response, $account);
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new MetricsFetchFailed('INVALID_RESPONSE', 'Réponse X inattendue. Nouvel essai automatique.', transient: true);
        }

        return $json;
    }

    /** @param array<string, string> $query */
    private function call(string $accessToken, string $path, array $query): Response
    {
        return Http::withToken($accessToken)->acceptJson()->connectTimeout(10)->timeout(20)->get(self::API_URL.$path, $query);
    }

    /** Erreurs X (format « Problem » : title, type, reason, detail) — ni jeton ni corps brut dans le message. */
    private function fromApiError(Response $response, SocialAccount $account): MetricsFetchFailed|MetricsNotSupported
    {
        $status = $response->status();
        $type = (string) ($response->json('type') ?? '');
        $reason = (string) ($response->json('reason') ?? '');
        $apiAccess = $status === 402 || $reason === 'client-not-enrolled' || str_contains($type, 'client-forbidden');
        if ($status === 401) {
            $this->tokens->rejected($account);
        }

        return match (true) {
            $status === 401 => new MetricsFetchFailed('TOKEN_INVALID', 'X a refusé l\'autorisation (expirée ou retirée). Reconnectez le compte X.'),
            $apiAccess => new MetricsFetchFailed('API_ACCESS_DENIED', 'X refuse la lecture des statistiques pour l\'application SoxialConnect (offre ou crédits du compte développeur X).'),
            $status === 429 => new MetricsFetchFailed('RATE_LIMITED', 'Limite de lecture X atteinte. Nouvel essai automatique.', true, $this->retryAfter($response)),
            $status === 404 || str_contains($type, 'resource-not-found') => new MetricsNotSupported('POST_NOT_FOUND', 'Publication introuvable sur X : aucune statistique à collecter.'),
            $status === 403 => new MetricsFetchFailed('PERMISSION_DENIED', 'X refuse la lecture de ces statistiques pour ce compte (autorisation insuffisante ou compte restreint). Reconnectez le compte X.'),
            $status >= 500 => new MetricsFetchFailed('PLATFORM_UNAVAILABLE', 'X rencontre un problème temporaire. Nouvel essai automatique.', true),
            $status === 400 => new MetricsFetchFailed('INVALID_REQUEST', 'X a refusé la demande de statistiques.'),
            default => new MetricsFetchFailed('HTTP_ERROR', "X a répondu par une erreur ({$status})."),
        };
    }

    /** Fin de fenêtre annoncée par X (x-rate-limit-reset, horodatage Unix). */
    private function retryAfter(Response $response): ?int
    {
        $reset = $response->header('x-rate-limit-reset');

        return ctype_digit($reset) ? max(1, (int) $reset - CarbonImmutable::now()->getTimestamp()) : null;
    }
}
