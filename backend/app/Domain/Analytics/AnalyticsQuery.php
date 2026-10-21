<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Enums\SyncStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSyncState;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Lecture des statistiques d'un utilisateur (GET /analytics). Isolation : seuls ses comptes sociaux ; un compte
 * ou une publication d'un autre utilisateur répond 404. Métriques NULL restituées null (« non fournie ») ;
 * taux d'engagement calculé à la lecture, jamais stocké. posts : identité des publications présentes dans les
 * points (titre, lien, date), limitée aux comptes de l'utilisateur (P4 — ADR-068).
 */
class AnalyticsQuery
{
    /** Points renvoyés au plus par requête (au-delà : meta.truncated). */
    public const MAX_POINTS = 5000;

    public const ENGAGEMENT_RATE_FORMULA = 'engagements ÷ impressions × 100, arrondi à 2 décimales ; null si l\'une des deux métriques n\'est pas fournie ou si impressions = 0';

    public function __construct(private readonly AnalyticsSyncService $sync) {}

    /**
     * @param  array<string, string>  $filters
     * @return array{points: list<array<string, mixed>>, posts: list<array<string, mixed>>, sync: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function forUser(User $user, array $filters): array
    {
        $to = $filters['to'] ?? now('UTC')->toDateString();
        $from = $filters['from'] ?? now('UTC')->subDays(29)->toDateString();
        $accounts = $this->accounts($user, $filters);
        $variantId = $filters['post_variant_id'] ?? null;
        if ($variantId !== null && ! PostVariant::query()->whereKey($variantId)->whereIn('social_account_id', $accounts->modelKeys())->exists()) {
            throw (new ModelNotFoundException)->setModel(PostVariant::class, [$variantId]);
        }

        $rows = AnalyticsMetric::query()
            ->whereIn('social_account_id', $accounts->modelKeys())
            ->whereBetween('metric_date', [$from, $to])
            ->when($variantId, fn ($q) => $q->where('post_variant_id', $variantId))
            ->when(($filters['scope'] ?? null) === 'account', fn ($q) => $q->whereNull('post_variant_id'))
            ->when(($filters['scope'] ?? null) === 'post', fn ($q) => $q->whereNotNull('post_variant_id'))
            ->when($filters['granularity'] ?? null, fn ($q, $g) => $q->where('granularity', $g))
            ->orderBy('metric_date')->orderBy('social_account_id')
            ->limit(self::MAX_POINTS + 1)->get();
        $platforms = $accounts->mapWithKeys(fn (SocialAccount $a) => [$a->getKey() => $a->platform?->key]);
        $kept = $rows->take(self::MAX_POINTS);

        return [
            'points' => $kept->map(fn (AnalyticsMetric $m) => $this->point($m, $platforms[$m->social_account_id] ?? null))->values()->all(),
            'posts' => $this->posts($kept->pluck('post_variant_id')->filter()->unique()->values()->all(), $accounts, $platforms),
            'sync' => $this->syncStates($accounts, $variantId),
            'meta' => ['from' => $from, 'to' => $to, 'truncated' => $rows->count() > self::MAX_POINTS, 'engagement_rate_formula' => self::ENGAGEMENT_RATE_FORMULA],
        ];
    }

    /** @return EloquentCollection<int, SocialAccount> */
    private function accounts(User $user, array $filters): EloquentCollection
    {
        $query = SocialAccount::query()->ownedBy($user)->with('platform')
            ->when($filters['platform'] ?? null, fn ($q, $key) => $q->whereHas('platform', fn ($p) => $p->where('key', $key)));
        if (isset($filters['social_account_id'])) {
            return new EloquentCollection([$query->findOrFail($filters['social_account_id'])]); // compte d'un autre : 404
        }

        return $query->get();
    }

    /**
     * Publications citées par les points : de quoi les nommer et les ouvrir, rien d'autre.
     *
     * @param  list<string>  $variantIds
     * @param  EloquentCollection<int, SocialAccount>  $accounts
     * @param  Collection<string, ?string>  $platforms
     * @return list<array<string, mixed>>
     */
    private function posts(array $variantIds, EloquentCollection $accounts, Collection $platforms): array
    {
        if ($variantIds === []) {
            return [];
        }

        return PostVariant::query()->with('post:id,title,body')
            ->whereKey($variantIds)->whereIn('social_account_id', $accounts->modelKeys())
            ->get(['id', 'post_id', 'social_account_id', 'options', 'body', 'external_url', 'published_at'])
            ->map(fn (PostVariant $v) => [
                'post_variant_id' => $v->getKey(),
                'post_id' => $v->post_id,
                'social_account_id' => $v->social_account_id,
                'platform' => $platforms[$v->social_account_id] ?? null,
                'title' => $this->title($v),
                'external_url' => $v->external_url,
                'published_at' => $v->published_at?->toIso8601String(),
            ])->values()->all();
    }

    /** Titre affiché : titre propre au réseau (ex. YouTube), sinon celui de la publication, sinon sa première ligne. */
    private function title(PostVariant $variant): ?string
    {
        $candidates = [$variant->options['title'] ?? null, $variant->post?->title, Str::before(trim((string) ($variant->body ?? $variant->post?->body)), "\n")];
        foreach ($candidates as $text) {
            if (is_string($text) && trim($text) !== '') {
                return Str::limit(trim($text), 120);
            }
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function point(AnalyticsMetric $m, ?string $platform): array
    {
        $rate = $m->engagements !== null && $m->impressions !== null && $m->impressions > 0
            ? round($m->engagements / $m->impressions * 100, 2) : null;

        return [
            'social_account_id' => $m->social_account_id,
            'platform' => $platform,
            'post_variant_id' => $m->post_variant_id,
            'date' => $m->metric_date->toDateString(),
            'granularity' => $m->granularity->value,
            ...collect(MetricsNormalizer::COUNTERS)->mapWithKeys(fn ($c) => [$c => $m->{$c} === null ? null : (int) $m->{$c}])->all(),
            'followers_delta' => $m->followers_delta,
            'engagement_rate' => $rate,
            'extra' => $m->extra,
        ];
    }

    /**
     * État de collecte par compte (et de la publication demandée) : état enregistré, ou déduit sans écriture
     * (réseau sans adaptateur, compte déconnecté, jamais collecté).
     *
     * @param  Collection<int, SocialAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function syncStates(Collection $accounts, ?string $variantId): array
    {
        $states = AnalyticsSyncState::query()->whereIn('social_account_id', $accounts->modelKeys())
            ->when($variantId, fn ($q) => $q->where('post_variant_id', $variantId), fn ($q) => $q->whereNull('post_variant_id'))
            ->get()->keyBy('social_account_id');

        return $accounts->map(function (SocialAccount $account) use ($states, $variantId) {
            $state = $states[$account->getKey()] ?? null;
            [$status, $code] = $state !== null ? [$state->status->value, $state->last_error_code] : $this->derived($account);

            return [
                'social_account_id' => $account->getKey(),
                'platform' => $account->platform?->key,
                'post_variant_id' => $variantId,
                'status' => $status,
                'last_attempt_at' => $state?->last_attempt_at?->toIso8601String(),
                'last_success_at' => $state?->last_success_at?->toIso8601String(),
                'last_error_code' => $code,
                'last_error_message' => $state?->last_error_message,
                'next_attempt_at' => $state?->next_attempt_at?->toIso8601String(),
            ];
        })->values()->all();
    }

    /** @return array{0: string, 1: ?string} */
    private function derived(SocialAccount $account): array
    {
        return match (true) {
            $account->status !== SocialAccountStatus::Connected => [SyncStatus::Failed->value, 'ACCOUNT_NOT_CONNECTED'],
            ! $this->sync->supports($account) => [SyncStatus::Unsupported->value, $account->isDemo() ? 'DEMO_ACCOUNT' : 'NO_ADAPTER'],
            default => [SyncStatus::Pending->value, null],
        };
    }
}
