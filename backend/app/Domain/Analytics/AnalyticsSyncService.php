<?php

namespace App\Domain\Analytics;

use App\Domain\Analytics\Contracts\MetricsAdapter;
use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Enums\SnapshotScope;
use App\Domain\Analytics\Enums\SyncStatus;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use App\Domain\Analytics\Exceptions\MetricsNotSupported;
use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Jobs\SyncAccountMetricsJob;
use App\Jobs\SyncPostMetricsJob;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Socle de collecte des statistiques (étape 7, P1 — ADR-063).
 *
 * - Un verrou par compte ou publication : jamais deux collectes simultanées du même élément.
 * - Écriture idempotente : réponse brute dans analytics_snapshots, métriques normalisées en upsert sur la clé
 *   unique (compte, publication, jour, granularité) — une relance ne crée jamais de doublon.
 * - Échec : état « failed » avec code, message nettoyé et date de tentative ; les dernières valeurs valides
 *   restent intactes. Échec temporaire : nouvelle tentative différée et bornée (analytics.sync.backoff), prise
 *   en charge par la commande planifiée — jamais de boucle de relance dans la file.
 * - Aucun adaptateur pour le réseau : « unsupported », rien n'est simulé.
 */
class AnalyticsSyncService
{
    public function __construct(
        private readonly MetricsAdapterRegistry $adapters,
        private readonly MetricsNormalizer $normalizer,
    ) {}

    public function syncAccount(string $accountId): ?AnalyticsSyncState
    {
        $account = SocialAccount::query()->with('platform')->find($accountId);
        if ($account === null) {
            return null; // supprimé entre-temps
        }

        return $this->run($account, null, function (MetricsAdapter $adapter) use ($account) {
            $to = CarbonImmutable::now('UTC')->startOfDay();
            $from = $to->subDays(max(1, (int) config('analytics.sync.account_window_days', 30)) - 1);

            return $adapter->fetchAccountMetrics($account, $from, $to);
        });
    }

    public function syncPost(string $variantId): ?AnalyticsSyncState
    {
        $variant = PostVariant::query()->with('socialAccount.platform')->find($variantId);
        $account = $variant?->socialAccount;
        if ($variant === null || $account === null) {
            return null;
        }
        if ($variant->status !== VariantStatus::Published || blank($variant->external_post_id)) {
            return $this->record($account, $variant, SyncStatus::Unsupported, 'POST_NOT_PUBLISHED', 'Cette publication n\'est pas publiée sur le réseau : aucune statistique à collecter.');
        }

        return $this->run($account, $variant, fn (MetricsAdapter $adapter) => $adapter->fetchPostMetrics($account, $variant));
    }

    /**
     * Collectes dues (commande planifiée) : comptes connectés dotés d'un adaptateur et publications récentes,
     * jamais collectés ou dont la prochaine tentative est échue. Renvoie le nombre de jobs envoyés.
     */
    public function dispatchDue(): int
    {
        $platforms = $this->adapters->platforms();
        if ($platforms === []) {
            return 0; // aucun adaptateur : aucune collecte, aucun chiffre simulé
        }
        $count = 0;
        $accounts = SocialAccount::query()->where('status', SocialAccountStatus::Connected->value)
            ->whereHas('platform', fn ($q) => $q->whereIn('key', $platforms))->get()
            ->reject(fn (SocialAccount $a) => $a->isDemo());
        foreach ($accounts as $account) {
            if ($this->isDue($account->getKey(), null)) {
                SyncAccountMetricsJob::dispatch($account->getKey());
                $count++;
            }
            foreach ($this->trackedVariants($account) as $variantId) {
                if ($this->isDue($account->getKey(), $variantId)) {
                    SyncPostMetricsJob::dispatch($variantId);
                    $count++;
                }
            }
        }

        return $count;
    }

    /** Synchronisation manuelle d'un compte (et de ses publications récentes), sans attendre l'intervalle minimal. */
    public function dispatchManual(SocialAccount $account): int
    {
        SyncAccountMetricsJob::dispatch($account->getKey());

        return 1 + collect($this->trackedVariants($account))->each(fn (string $id) => SyncPostMetricsJob::dispatch($id))->count();
    }

    /** Un adaptateur existe pour ce compte (sinon la collecte est « unsupported »). */
    public function supports(SocialAccount $account): bool
    {
        return $this->adapters->for($account->platform?->key) !== null && ! $account->isDemo();
    }

    /** @return list<string> publications publiées suivies (fenêtre analytics.sync.post_window_days) */
    private function trackedVariants(SocialAccount $account): array
    {
        return PostVariant::query()->where('social_account_id', $account->getKey())
            ->where('status', VariantStatus::Published->value)->whereNotNull('external_post_id')
            ->where('published_at', '>=', now()->subDays(max(1, (int) config('analytics.sync.post_window_days', 90))))
            ->pluck('id')->all();
    }

    private function isDue(string $accountId, ?string $variantId): bool
    {
        $state = $this->state($accountId, $variantId);
        if ($state === null || $state->status === SyncStatus::Pending) {
            return true;
        }
        if ($state->status === SyncStatus::Running) {
            // Worker interrompu : l'état « running » expire avec le verrou.
            return $state->last_attempt_at === null || $state->last_attempt_at->lt(now()->subSeconds((int) config('analytics.sync.lock_seconds', 600)));
        }

        return $state->next_attempt_at !== null && $state->next_attempt_at->lte(now());
    }

    /** @param callable(MetricsAdapter): MetricsResult $fetch */
    private function run(SocialAccount $account, ?PostVariant $variant, callable $fetch): ?AnalyticsSyncState
    {
        if ($account->status !== SocialAccountStatus::Connected) {
            return $this->record($account, $variant, SyncStatus::Failed, 'ACCOUNT_NOT_CONNECTED', 'Le compte n\'est plus connecté : reconnectez-le pour reprendre la collecte des statistiques.');
        }
        if ($account->isDemo()) {
            return $this->record($account, $variant, SyncStatus::Unsupported, 'DEMO_ACCOUNT', 'Compte de démonstration : aucune statistique réelle.');
        }
        $adapter = $this->adapters->for($account->platform?->key);
        if ($adapter === null) {
            return $this->record($account, $variant, SyncStatus::Unsupported, 'NO_ADAPTER', 'La collecte des statistiques n\'est pas encore disponible pour ce réseau.');
        }

        $lock = Cache::lock('analytics-sync:'.$account->getKey().':'.($variant?->getKey() ?? 'account'), (int) config('analytics.sync.lock_seconds', 600));
        if (! $lock->get()) {
            return $this->state($account->getKey(), $variant?->getKey()); // collecte déjà en cours : rien en double
        }
        try {
            $state = $this->stateFor($account, $variant);
            $state->forceFill(['status' => SyncStatus::Running, 'last_attempt_at' => now()])->save();
            try {
                $result = $fetch($adapter);
                $this->store($account, $variant, $result);
            } catch (MetricsNotSupported $e) {
                return $this->record($account, $variant, SyncStatus::Unsupported, $e->errorCode, $e->getMessage());
            } catch (MetricsFetchFailed $e) {
                return $this->failed($state, $e->errorCode, $e->getMessage(), $e->transient, $e->retryAfterSeconds);
            } catch (Throwable $e) {
                Log::error('analytics.sync_failed', ['social_account_id' => $account->getKey(), 'post_variant_id' => $variant?->getKey(), 'exception' => $e::class]);

                return $this->failed($state, 'UNEXPECTED_ERROR', 'La collecte des statistiques a échoué de façon inattendue.', true, null);
            }
            $state->forceFill([
                'status' => SyncStatus::Succeeded, 'last_success_at' => now(), 'last_error_code' => null, 'last_error_message' => null,
                'consecutive_failures' => 0, 'next_attempt_at' => now()->addSeconds(max(60, (int) config('analytics.sync.min_interval_seconds', 3600))),
            ])->save();
            Log::info('analytics.synced', ['social_account_id' => $account->getKey(), 'post_variant_id' => $variant?->getKey(), 'points' => count($result->points)]);

            return $state;
        } finally {
            $lock->release();
        }
    }

    /** Réponse brute + métriques normalisées, dans une transaction (tout ou rien). */
    private function store(SocialAccount $account, ?PostVariant $variant, MetricsResult $result): void
    {
        $rows = $this->normalizer->normalize($result); // incohérence → MetricsFetchFailed, rien d'écrit
        DB::transaction(function () use ($account, $variant, $result, $rows) {
            $snapshot = AnalyticsSnapshot::query()->create([
                'social_account_id' => $account->getKey(),
                'post_variant_id' => $variant?->getKey(),
                'scope' => $variant ? SnapshotScope::Post : SnapshotScope::Account,
                'period_start' => $result->periodStart,
                'period_end' => $result->periodEnd,
                'api_version' => $result->apiVersion,
                'payload' => $result->payload,
                'fetched_at' => now(),
            ]);
            foreach ($rows as $row) {
                AnalyticsMetric::query()->updateOrCreate(
                    ['social_account_id' => $account->getKey(), 'post_variant_id' => $variant?->getKey(), 'metric_date' => $row['metric_date'], 'granularity' => $row['granularity']],
                    [...$row, 'snapshot_id' => $snapshot->getKey()],
                );
            }
        });
    }

    private function failed(AnalyticsSyncState $state, string $code, string $message, bool $transient, ?int $retryAfter): AnalyticsSyncState
    {
        $failures = $state->consecutive_failures + 1;
        $backoff = array_values((array) config('analytics.sync.backoff', [300, 1800, 7200]));
        // Nouvelle tentative seulement pour un échec temporaire et dans la limite des délais prévus ; sinon
        // attente d'une synchronisation manuelle (jamais de boucle).
        $next = $transient && $failures <= count($backoff) ? now()->addSeconds(max((int) $retryAfter, (int) $backoff[$failures - 1])) : null;
        $state->forceFill([
            'status' => SyncStatus::Failed, 'last_error_code' => mb_substr($code, 0, 64), 'last_error_message' => $this->clean($message),
            'consecutive_failures' => $failures, 'next_attempt_at' => $next,
        ])->save();
        Log::warning('analytics.sync_error', ['social_account_id' => $state->social_account_id, 'post_variant_id' => $state->post_variant_id, 'code' => $code, 'retry' => $next !== null]);

        return $state;
    }

    /** État non tenté (compte déconnecté, réseau sans collecte…) : aucune donnée n'est écrite. */
    private function record(SocialAccount $account, ?PostVariant $variant, SyncStatus $status, string $code, string $message): AnalyticsSyncState
    {
        $state = $this->stateFor($account, $variant);
        $state->forceFill([
            'status' => $status, 'last_error_code' => $code, 'last_error_message' => $this->clean($message), 'consecutive_failures' => 0,
            // Revérifié une fois par jour (un adaptateur ou une reconnexion a pu arriver entre-temps).
            'next_attempt_at' => now()->addDay(),
        ])->save();

        return $state;
    }

    private function stateFor(SocialAccount $account, ?PostVariant $variant): AnalyticsSyncState
    {
        return $this->state($account->getKey(), $variant?->getKey())
            ?? AnalyticsSyncState::query()->create(['social_account_id' => $account->getKey(), 'post_variant_id' => $variant?->getKey(), 'status' => SyncStatus::Pending]);
    }

    private function state(string $accountId, ?string $variantId): ?AnalyticsSyncState
    {
        return AnalyticsSyncState::query()->where('social_account_id', $accountId)
            ->when($variantId, fn ($q) => $q->where('post_variant_id', $variantId), fn ($q) => $q->whereNull('post_variant_id'))->first();
    }

    /** Message destiné à l'utilisateur : jetons et secrets masqués, longueur bornée. */
    private function clean(string $message): string
    {
        $message = (string) preg_replace(['/(Bearer|token|access_token|refresh_token|secret|key)([\s=:"]+)[A-Za-z0-9._\-~+\/]{8,}/i'], ['$1$2[masqué]'], $message);

        return mb_substr($message, 0, 500);
    }
}
