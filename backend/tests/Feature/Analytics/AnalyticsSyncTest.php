<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalyticsSyncService;
use App\Domain\Analytics\Data\MetricsResult;
use App\Domain\Analytics\Enums\Granularity;
use App\Domain\Analytics\Enums\SyncStatus;
use App\Domain\Analytics\Exceptions\MetricsFetchFailed;
use App\Domain\Analytics\Exceptions\MetricsNotSupported;
use App\Domain\Analytics\MetricsNormalizer;
use App\Jobs\SyncAccountMetricsJob;
use App\Jobs\SyncPostMetricsJob;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Socle de collecte des statistiques (étape 7, P1 — ADR-063). SIMULÉ : l'adaptateur de plateforme
 * (FakeMetricsAdapter, réponses scriptées) ; aucun réseau réel n'est appelé. RÉEL : normalisation, écritures
 * MySQL (contraintes d'unicité), états de collecte, verrous, file, purge.
 */
class AnalyticsSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        FakeMetricsAdapter::reset();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
        config(['analytics.adapters' => [$this->account->platform->key => FakeMetricsAdapter::class]]);
    }

    private function sync(): ?AnalyticsSyncState
    {
        return app(AnalyticsSyncService::class)->syncAccount($this->account->id);
    }

    private function points(array $days): void
    {
        FakeMetricsAdapter::$result = new MetricsResult(['source' => 'test'], array_map(fn ($d) => MetricsResult::point($d[0], $d[1]), $days), CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-02'), 'v-test');
    }

    // --- Normalisation : zéro, absente, incohérente ----------------------------------------------

    public function test_zero_stays_zero_absent_stays_null_and_unknown_metrics_go_to_extra(): void
    {
        $rows = app(MetricsNormalizer::class)->normalize(new MetricsResult([], [
            MetricsResult::point('2026-10-01', ['impressions' => 0, 'reach' => null, 'likes' => '12', 'followers_delta' => -3, 'watch_time_minutes' => 42]),
        ], CarbonImmutable::now(), CarbonImmutable::now()));

        $this->assertSame(0, $rows[0]['impressions'], '0 réel conservé.');
        $this->assertNull($rows[0]['reach'], 'Fournie à null : non disponible.');
        $this->assertNull($rows[0]['views'], 'Absente : non disponible, jamais 0.');
        $this->assertSame([12, -3], [$rows[0]['likes'], $rows[0]['followers_delta']]);
        $this->assertSame(['watch_time_minutes' => 42], $rows[0]['extra']);
    }

    public function test_an_incoherent_platform_answer_is_rejected_instead_of_silently_corrected(): void
    {
        foreach ([['impressions' => -1], ['likes' => 'beaucoup'], ['views' => 3.5]] as $metrics) {
            try {
                app(MetricsNormalizer::class)->normalize(new MetricsResult([], [MetricsResult::point('2026-10-01', $metrics)], CarbonImmutable::now(), CarbonImmutable::now()));
                $this->fail('Réponse incohérente acceptée : '.json_encode($metrics));
            } catch (MetricsFetchFailed $e) {
                $this->assertSame('INVALID_METRICS', $e->errorCode);
            }
        }
        $this->expectException(MetricsFetchFailed::class);
        app(MetricsNormalizer::class)->normalize(new MetricsResult([], [MetricsResult::point('2026-02-31', ['likes' => 1])], CarbonImmutable::now(), CarbonImmutable::now()));
    }

    // --- Création, lecture, idempotence ------------------------------------------------------------

    public function test_a_successful_sync_stores_the_raw_answer_the_normalized_metrics_and_its_state(): void
    {
        $this->points([['2026-10-01', ['impressions' => 120, 'likes' => 0]], ['2026-10-02', ['impressions' => 80]]]);
        $state = $this->sync();

        $this->assertSame(SyncStatus::Succeeded, $state->status);
        $this->assertNotNull($state->last_success_at);
        $this->assertTrue($state->next_attempt_at->gt(now()->addMinutes(55)), 'Intervalle minimal avant la prochaine collecte.');
        $this->assertSame(1, AnalyticsSnapshot::query()->count());
        $day = AnalyticsMetric::query()->where('metric_date', '2026-10-01')->firstOrFail();
        $this->assertSame([120, 0, null], [(int) $day->impressions, (int) $day->likes, $day->reach]);
        $this->assertSame(Granularity::Day, $day->granularity);
        $this->assertNotNull($day->snapshot_id);
    }

    public function test_a_repeated_sync_updates_the_same_rows_and_never_duplicates_them(): void
    {
        $this->points([['2026-10-01', ['impressions' => 100]]]);
        $this->sync();
        $this->points([['2026-10-01', ['impressions' => 150, 'comments' => 2]], ['2026-10-01', ['impressions' => 160, 'comments' => 2]]]);
        $this->sync();
        $this->sync();

        $this->assertSame(1, AnalyticsMetric::query()->count(), 'Un seul point par compte, jour et granularité.');
        $this->assertSame(160, (int) AnalyticsMetric::query()->value('impressions'));
        $this->assertSame(3, AnalyticsSnapshot::query()->count(), 'Une réponse brute par collecte (journal), purgée à 13 mois.');
        $this->assertSame(1, AnalyticsSyncState::query()->count());
    }

    // --- Erreurs, nouvelles tentatives ------------------------------------------------------------

    public function test_a_failed_sync_keeps_the_last_valid_values_and_records_a_clean_error(): void
    {
        $this->points([['2026-10-01', ['impressions' => 100]]]);
        $this->sync();
        FakeMetricsAdapter::$exception = new MetricsFetchFailed('RATE_LIMITED', 'Limite atteinte (Bearer abcdefghijklmnopqrstuv).', transient: true, retryAfterSeconds: 60);
        $state = $this->sync();

        $this->assertSame([SyncStatus::Failed, 'RATE_LIMITED', 1], [$state->status, $state->last_error_code, $state->consecutive_failures]);
        $this->assertStringNotContainsString('abcdefghijklmnopqrstuv', $state->last_error_message, 'Aucun jeton dans le message enregistré.');
        $this->assertNotNull($state->last_attempt_at);
        $this->assertEqualsWithDelta(now()->addSeconds(300)->timestamp, $state->next_attempt_at->timestamp, 5, 'Premier délai de nouvelle tentative.');
        $this->assertSame(100, (int) AnalyticsMetric::query()->value('impressions'), 'Dernière valeur valide intacte : jamais remplacée par 0 ou NULL.');
    }

    public function test_retries_are_bounded_and_a_definitive_failure_waits_for_a_manual_sync(): void
    {
        FakeMetricsAdapter::$exception = new MetricsFetchFailed('TIMEOUT', 'Délai dépassé.', transient: true);
        foreach ([1, 2, 3] as $n) {
            $this->assertNotNull($this->sync()->next_attempt_at, "Tentative {$n} : nouvelle tentative programmée.");
        }
        $this->assertNull($this->sync()->next_attempt_at, 'Au-delà des délais prévus : plus de relance automatique.');

        FakeMetricsAdapter::$exception = new MetricsFetchFailed('PERMISSION_REVOKED', 'Autorisation retirée.');
        $this->assertNull($this->sync()->next_attempt_at, 'Échec définitif : aucune relance automatique.');
    }

    public function test_a_network_without_adapter_or_a_disconnected_account_is_never_simulated(): void
    {
        config(['analytics.adapters' => []]);
        $state = $this->sync();
        $this->assertSame([SyncStatus::Unsupported, 'NO_ADAPTER'], [$state->status, $state->last_error_code]);

        config(['analytics.adapters' => [$this->account->platform->key => FakeMetricsAdapter::class]]);
        $this->account->forceFill(['status' => 'expired'])->save();
        $disconnected = $this->sync();
        $this->assertSame([SyncStatus::Failed, 'ACCOUNT_NOT_CONNECTED'], [$disconnected->status, $disconnected->last_error_code]);
        $this->assertSame(0, FakeMetricsAdapter::$calls, 'Compte déconnecté : la plateforme n\'est jamais appelée.');

        $this->account->forceFill(['status' => 'connected'])->save();
        FakeMetricsAdapter::$exception = new MetricsNotSupported('PERSONAL_ACCOUNT', 'Statistiques réservées aux comptes professionnels.');
        $this->assertSame([SyncStatus::Unsupported, 'PERSONAL_ACCOUNT'], [$this->sync()->status, $this->sync()->last_error_code]);
        $this->assertSame(0, AnalyticsMetric::query()->count());
        $this->assertSame(1, AnalyticsSyncState::query()->count());
    }

    public function test_a_sync_already_running_for_the_same_account_is_not_run_twice(): void
    {
        $this->points([['2026-10-01', ['impressions' => 1]]]);
        $lock = Cache::lock('analytics-sync:'.$this->account->id.':account', 600);
        $this->assertTrue($lock->get());
        try {
            $this->sync();
            $this->assertSame(0, FakeMetricsAdapter::$calls, 'Collecte concurrente ignorée.');
        } finally {
            $lock->release();
        }
        $this->sync();
        $this->assertSame(1, FakeMetricsAdapter::$calls);
    }

    // --- Publications -------------------------------------------------------------------------------

    public function test_a_published_post_gets_its_own_metrics_and_an_unpublished_one_none(): void
    {
        $published = $this->variant(published: true);
        FakeMetricsAdapter::$result = new MetricsResult([], [MetricsResult::point('2026-10-05', ['views' => 42, 'likes' => 3], Granularity::Lifetime)], CarbonImmutable::now(), CarbonImmutable::now());
        $this->assertSame(SyncStatus::Succeeded, app(AnalyticsSyncService::class)->syncPost($published->id)->status);
        $this->assertSame([42, 'lifetime'], [(int) AnalyticsMetric::query()->where('post_variant_id', $published->id)->value('views'), AnalyticsMetric::query()->value('granularity')->value]);

        $draft = $this->variant(published: false);
        $state = app(AnalyticsSyncService::class)->syncPost($draft->id);
        $this->assertSame([SyncStatus::Unsupported, 'POST_NOT_PUBLISHED'], [$state->status, $state->last_error_code]);
    }

    // --- Planification et file -----------------------------------------------------------------------

    public function test_only_due_items_are_dispatched_on_the_analytics_queue_and_never_twice(): void
    {
        Queue::fake();
        $recent = $this->variant(published: true);
        $old = $this->variant(published: true, publishedAt: now()->subDays(200));
        $otherNetwork = SocialAccount::factory()->for($this->user)->create(); // aucun adaptateur pour ce réseau

        $this->assertSame(2, app(AnalyticsSyncService::class)->dispatchDue(), 'Compte + publication récente (publication ancienne et réseau sans collecte ignorés).');
        Queue::assertPushedOn('analytics', SyncAccountMetricsJob::class, fn ($job) => $job->socialAccountId === $this->account->id);
        Queue::assertPushedOn('analytics', SyncPostMetricsJob::class, fn ($job) => $job->postVariantId === $recent->id);
        Queue::assertNotPushed(SyncPostMetricsJob::class, fn ($job) => $job->postVariantId === $old->id);
        Queue::assertNotPushed(SyncAccountMetricsJob::class, fn ($job) => $job->socialAccountId === $otherNetwork->id);

        // Job unique dans la file : un second envoi du même élément est ignoré.
        app(AnalyticsSyncService::class)->dispatchDue();
        Queue::assertPushed(SyncAccountMetricsJob::class, 1);

        // Collecte réussie récemment : pas due avant l'intervalle minimal.
        Cache::flush();
        AnalyticsSyncState::query()->create(['social_account_id' => $this->account->id, 'status' => SyncStatus::Succeeded, 'next_attempt_at' => now()->addHour()]);
        AnalyticsSyncState::query()->create(['social_account_id' => $this->account->id, 'post_variant_id' => $recent->id, 'status' => SyncStatus::Failed, 'next_attempt_at' => null]);
        $this->assertSame(0, app(AnalyticsSyncService::class)->dispatchDue());
    }

    public function test_the_scheduled_command_dispatches_nothing_without_any_collection_adapter(): void
    {
        Queue::fake();
        config(['analytics.adapters' => []]);
        $this->artisan('analytics:sync')->expectsOutputToContain('0 collecte')->assertSuccessful();
        Queue::assertNothingPushed();
    }

    // --- Conservation ---------------------------------------------------------------------------------

    public function test_raw_answers_older_than_13_months_are_purged_and_normalized_metrics_kept(): void
    {
        $this->points([['2026-10-01', ['impressions' => 5]]]);
        $this->sync();
        $old = AnalyticsSnapshot::query()->firstOrFail();
        $old->forceFill(['fetched_at' => now()->subMonths(13)->subDay()])->save();
        $recent = AnalyticsSnapshot::query()->create(['social_account_id' => $this->account->id, 'scope' => 'account', 'period_start' => now(), 'period_end' => now(),
            'payload' => [], 'fetched_at' => now()->subMonths(12)]);

        $this->artisan('analytics:purge')->assertSuccessful();

        $this->assertNull(AnalyticsSnapshot::query()->find($old->id));
        $this->assertNotNull(AnalyticsSnapshot::query()->find($recent->id), 'Réponse de moins de 13 mois conservée.');
        $metric = AnalyticsMetric::query()->firstOrFail();
        $this->assertSame([5, null], [(int) $metric->impressions, $metric->snapshot_id], 'Métrique normalisée conservée, lien vers le brut effacé.');
    }

    private function variant(bool $published, ?\DateTimeInterface $publishedAt = null): PostVariant
    {
        $post = Post::factory()->for($this->user)->create();
        $variant = new PostVariant(['social_account_id' => $this->account->id, 'format' => 'feed']);
        $variant->post_id = $post->id;
        $variant->forceFill($published
            ? ['status' => 'published', 'external_post_id' => 'ext-'.uniqid(), 'published_at' => $publishedAt ?? now()->subDays(2)]
            : ['status' => 'draft'])->save();

        return $variant;
    }
}
