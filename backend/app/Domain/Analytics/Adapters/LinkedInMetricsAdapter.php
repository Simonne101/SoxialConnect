<?php

namespace App\Domain\Analytics\Adapters;

use App\Domain\Analytics\Contracts\MetricsAdapter;
use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Enums\Granularity;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use App\Domain\Analytics\Exceptions\MetricsNotSupported;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\LinkedInTokenManager;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Statistiques LinkedIn d'un profil membre (étape 7, P3 — ADR-067) : API memberCreatorPostAnalytics
 * (learn.microsoft.com/linkedin/marketing/community-management/members/post-statistics), lecture seule.
 *
 * Exige le scope r_member_postAnalytics (produit « Community Management API », soumis à l'approbation de
 * LinkedIn). Tant que le jeton du compte ne l'a pas, AUCUN appel : « unsupported » / PERMISSION_REQUIRED,
 * revérifié chaque jour (après approbation et reconnexion, la collecte démarre seule). La connexion LinkedIn
 * n'est pas modifiée par cet adaptateur.
 *
 * - Publication (finder entity, agrégation TOTAL = cumul depuis la publication) : impressions, membres
 *   atteints, réactions, commentaires, partages, enregistrements, clics de lien — un appel par métrique.
 * - Compte (finder me, cumul de toutes les publications du membre) : impressions et membres atteints
 *   seulement (LinkedIn indique que réactions, commentaires et partages agrégés ne sont pas encore cohérents
 *   avec son interface). Abonnés : autre autorisation, non demandée → null.
 *
 * Point « lifetime » daté du jour de collecte ; métrique non renvoyée → null, jamais 0.
 */
class LinkedInMetricsAdapter implements MetricsAdapter
{
    public const REQUIRED_SCOPE = 'r_member_postAnalytics';

    private const API_URL = 'https://api.linkedin.com/rest/memberCreatorPostAnalytics';

    /** queryType LinkedIn → métrique normalisée. */
    private const POST_METRICS = [
        'IMPRESSION' => 'impressions',
        'MEMBERS_REACHED' => 'reach',
        'REACTION' => 'likes',
        'COMMENT' => 'comments',
        'RESHARE' => 'shares',
        'POST_SAVE' => 'saves',
        'LINK_CLICKS' => 'clicks',
    ];

    private const ACCOUNT_METRICS = [
        'IMPRESSION' => 'impressions',
        'MEMBERS_REACHED' => 'reach',
    ];

    public function __construct(private readonly LinkedInTokenManager $tokens) {}

    public function fetchAccountMetrics(SocialAccount $account, CarbonImmutable $from, CarbonImmutable $to): MetricsResult
    {
        $accessToken = $this->authorize($account);
        [$metrics, $responses] = $this->collect($account, $accessToken, 'q=me', self::ACCOUNT_METRICS);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new MetricsResult(
            payload: ['endpoint' => 'memberCreatorPostAnalytics?q=me', 'responses' => $responses],
            points: [MetricsResult::point($today->toDateString(), [...$metrics, 'followers' => null], Granularity::Lifetime)],
            periodStart: $today,
            periodEnd: $today,
            apiVersion: $this->version(),
        );
    }

    public function fetchPostMetrics(SocialAccount $account, PostVariant $variant): MetricsResult
    {
        if ($variant->social_account_id !== $account->getKey()) {
            throw new MetricsNotSupported('ACCOUNT_MISMATCH', 'Cette publication n\'appartient pas à ce compte.');
        }
        $urn = (string) $variant->external_post_id;
        if (! preg_match('/^urn:li:(share|ugcPost):\d+$/', $urn, $m)) {
            throw new MetricsNotSupported('POST_NOT_FOUND', 'Identifiant de publication LinkedIn non reconnu : aucune statistique à collecter.');
        }
        $accessToken = $this->authorize($account);
        // Rest.li 2.0 : entity=(share:urn%3Ali%3Ashare%3A…) — l'URN est encodé, pas les parenthèses.
        $entity = 'q=entity&entity=('.($m[1] === 'share' ? 'share' : 'ugc').':'.rawurlencode($urn).')';
        [$metrics, $responses] = $this->collect($account, $accessToken, $entity, self::POST_METRICS);
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return new MetricsResult(
            payload: ['endpoint' => 'memberCreatorPostAnalytics?q=entity', 'entity' => $urn, 'responses' => $responses],
            points: [MetricsResult::point($today->toDateString(), $metrics, Granularity::Lifetime)],
            periodStart: $variant->published_at !== null ? CarbonImmutable::parse($variant->published_at)->utc()->startOfDay() : $today,
            periodEnd: $today,
            apiVersion: $this->version(),
        );
    }

    /** Jeton valide ET autorisation de lecture des statistiques ; sinon aucun appel. */
    private function authorize(SocialAccount $account): string
    {
        $scopes = (array) ($this->tokens->activeToken($account)?->scopes ?? []);
        if (! in_array(self::REQUIRED_SCOPE, $scopes, true)) {
            throw new MetricsNotSupported('PERMISSION_REQUIRED', 'LinkedIn ne donne pas encore accès aux statistiques de ce profil : l\'autorisation « r_member_postAnalytics » (produit Community Management API, soumis à l\'approbation de LinkedIn) doit être accordée à l\'application, puis le compte reconnecté.');
        }
        try {
            return $this->tokens->accessTokenFor($account);
        } catch (OAuthException $e) {
            throw new MetricsFetchFailed($e->errorCode, $e->getMessage(), transient: $e->transient);
        }
    }

    /**
     * Un appel par métrique (agrégation TOTAL : un seul élément attendu).
     *
     * @param  array<string, string>  $types  queryType → métrique normalisée
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function collect(SocialAccount $account, string $accessToken, string $finder, array $types): array
    {
        $metrics = [];
        $responses = [];
        foreach ($types as $queryType => $metric) {
            $json = $this->get($account, $accessToken, self::API_URL.'?'.$finder.'&queryType='.$queryType.'&aggregation=TOTAL');
            $responses[$queryType] = $json;
            $element = collect((array) ($json['elements'] ?? []))->first(fn ($e) => is_array($e) && array_key_exists('count', $e));
            $metrics[$metric] = $element['count'] ?? null;
        }

        return [$metrics, $responses];
    }

    /** @return array<string, mixed> */
    private function get(SocialAccount $account, string $accessToken, string $url): array
    {
        try {
            $response = Http::withToken($accessToken)->acceptJson()->connectTimeout(10)->timeout(20)
                ->withHeaders(['Linkedin-Version' => $this->version(), 'X-Restli-Protocol-Version' => '2.0.0'])
                ->get($url);
        } catch (ConnectionException) {
            throw new MetricsFetchFailed('NETWORK_ERROR', 'LinkedIn est momentanément injoignable. Nouvel essai automatique.', transient: true);
        }
        if (! $response->successful()) {
            throw $this->fromApiError($response, $account);
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new MetricsFetchFailed('INVALID_RESPONSE', 'Réponse LinkedIn inattendue. Nouvel essai automatique.', transient: true);
        }

        return $json;
    }

    /** Erreurs LinkedIn — ni jeton ni corps brut dans le message. */
    private function fromApiError(Response $response, SocialAccount $account): MetricsFetchFailed|MetricsNotSupported
    {
        $status = $response->status();
        $code = (string) ($response->json('code') ?? '');
        if ($status === 401) {
            $this->tokens->rejected($account);
        }

        return match (true) {
            $status === 401 => new MetricsFetchFailed('TOKEN_INVALID', 'LinkedIn a refusé l\'autorisation (expirée ou retirée). Reconnectez le compte LinkedIn.'),
            $status === 403 => new MetricsNotSupported('PERMISSION_REQUIRED', 'LinkedIn refuse l\'accès aux statistiques de ce profil (autorisation r_member_postAnalytics non approuvée pour l\'application).'),
            $status === 404 => new MetricsNotSupported('POST_NOT_FOUND', 'Publication introuvable sur LinkedIn : aucune statistique à collecter.'),
            $status === 426 || $code === 'NONEXISTENT_VERSION' || $code === 'VERSION_MISSING' => new MetricsFetchFailed('API_VERSION_UNSUPPORTED', 'Version de l\'API LinkedIn refusée ('.$this->version().') : mettez à jour ANALYTICS_LINKEDIN_API_VERSION.'),
            $status === 429 => new MetricsFetchFailed('RATE_LIMITED', 'Limite de requêtes LinkedIn atteinte. Nouvel essai automatique.', true),
            $status >= 500 => new MetricsFetchFailed('PLATFORM_UNAVAILABLE', 'LinkedIn rencontre un problème temporaire. Nouvel essai automatique.', true),
            $status === 400 => new MetricsFetchFailed('INVALID_REQUEST', 'LinkedIn a refusé la demande de statistiques.'),
            default => new MetricsFetchFailed('HTTP_ERROR', "LinkedIn a répondu par une erreur ({$status})."),
        };
    }

    private function version(): string
    {
        return (string) config('analytics.linkedin.api_version', '202609');
    }
}
