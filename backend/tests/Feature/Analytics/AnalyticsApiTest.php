<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Enums\SyncStatus;
use App\Jobs\SyncAccountMetricsJob;
use App\Jobs\SyncPostMetricsJob;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\SocialPlatform;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * GET /api/v1/analytics et POST /api/v1/analytics/sync (étape 7, P1 — ADR-063) : isolation stricte, filtres
 * limités aux données stockées, NULL ≠ 0 restitué tel quel, taux d'engagement calculé à la lecture, état de
 * collecte réel ou déduit (jamais de chiffre simulé).
 */
class AnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
    }

    private function analytics(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->user)->fromSpa()->getJson('/api/v1/analytics?'.http_build_query($query));
    }

    private function metric(SocialAccount $account, string $date, array $values, ?PostVariant $variant = null, string $granularity = 'day'): void
    {
        AnalyticsMetric::query()->create(['social_account_id' => $account->id, 'post_variant_id' => $variant?->id, 'metric_date' => $date, 'granularity' => $granularity, ...$values]);
    }

    public function test_points_restore_zero_and_null_as_stored_and_compute_the_engagement_rate_on_read(): void
    {
        $this->metric($this->account, '2026-10-01', ['impressions' => 200, 'engagements' => 13, 'likes' => 0]);
        $this->metric($this->account, '2026-10-02', ['impressions' => 0, 'engagements' => 0]);
        $this->metric($this->account, '2026-10-03', ['engagements' => 4]);

        $res = $this->analytics(['from' => '2026-10-01', 'to' => '2026-10-03'])->assertOk();
        $points = $res->json('data.points');
        $this->assertSame([200, 13, 0, null], [$points[0]['impressions'], $points[0]['engagements'], $points[0]['likes'], $points[0]['reach']]);
        $this->assertSame(6.5, $points[0]['engagement_rate']);
        $this->assertNull($points[1]['engagement_rate'], 'Impressions = 0 : taux non calculable.');
        $this->assertNull($points[2]['engagement_rate'], 'Impressions non fournies : taux non calculable.');
        $this->assertNull($points[2]['impressions']);
        $res->assertJsonPath('meta.from', '2026-10-01')->assertJsonPath('meta.truncated', false);
        $this->assertStringContainsString('impressions', $res->json('meta.engagement_rate_formula'));
    }

    public function test_another_users_statistics_are_never_returned_and_their_resources_answer_404(): void
    {
        $other = User::factory()->create();
        $foreign = SocialAccount::factory()->for($other)->create();
        $this->metric($foreign, '2026-10-01', ['impressions' => 999]);
        $this->metric($this->account, '2026-10-01', ['impressions' => 1]);
        $foreignVariant = $this->variant($foreign);

        $points = $this->analytics(['from' => '2026-10-01', 'to' => '2026-10-01'])->assertOk()->json('data.points');
        $this->assertSame([$this->account->id], array_column($points, 'social_account_id'));
        $this->analytics(['social_account_id' => $foreign->id])->assertNotFound();
        $this->analytics(['post_variant_id' => $foreignVariant->id])->assertNotFound();
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/analytics/sync', ['social_account_id' => $foreign->id])->assertNotFound();
    }

    public function test_filters_apply_to_period_account_network_scope_post_and_granularity(): void
    {
        $second = SocialAccount::factory()->for($this->user)->create();
        $variant = $this->variant($this->account);
        $this->metric($this->account, '2026-09-30', ['impressions' => 1]);
        $this->metric($this->account, '2026-10-01', ['impressions' => 2]);
        $this->metric($second, '2026-10-01', ['impressions' => 3]);
        $this->metric($this->account, '2026-10-01', ['views' => 4], $variant, 'lifetime');

        $ids = fn ($q) => count($this->analytics(['from' => '2026-10-01', 'to' => '2026-10-31', ...$q])->assertOk()->json('data.points'));
        $this->assertSame(3, $ids([]), 'Période : le 30 septembre est exclu.');
        $this->assertSame(2, $ids(['social_account_id' => $this->account->id]));
        $this->assertSame(1, $ids(['platform' => $second->platform->key]));
        $this->assertSame(2, $ids(['scope' => 'account']));
        $this->assertSame(1, $ids(['scope' => 'post']));
        $this->assertSame(1, $ids(['post_variant_id' => $variant->id]));
        $this->assertSame(1, $ids(['granularity' => 'lifetime']));
    }

    public function test_invalid_filters_are_refused(): void
    {
        $this->analytics(['from' => '2026-10-05', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('from');
        $this->analytics(['from' => '2025-01-01', 'to' => '2026-10-01'])->assertUnprocessable()->assertJsonValidationErrors('to');
        $this->analytics(['granularity' => 'week'])->assertUnprocessable();
        $this->analytics(['scope' => 'everything'])->assertUnprocessable();
        $this->analytics(['platform' => 'myspace'])->assertUnprocessable();
        $this->analytics(['from' => '01/10/2026'])->assertUnprocessable();
    }

    public function test_each_account_reports_its_real_or_derived_collection_state(): void
    {
        $failed = SocialAccount::factory()->for($this->user)->create();
        AnalyticsSyncState::query()->create(['social_account_id' => $failed->id, 'status' => SyncStatus::Failed, 'last_error_code' => 'RATE_LIMITED',
            'last_error_message' => 'Limite atteinte.', 'last_attempt_at' => now(), 'consecutive_failures' => 1, 'next_attempt_at' => now()->addMinutes(5)]);
        $expired = SocialAccount::factory()->for($this->user)->expired()->create();

        $sync = collect($this->analytics()->assertOk()->json('data.sync'))->keyBy('social_account_id');
        $this->assertSame(['unsupported', 'NO_ADAPTER'], [$sync[$this->account->id]['status'], $sync[$this->account->id]['last_error_code']], 'Réseau sans collecte : jamais « en attente » ni simulé.');
        $this->assertSame(['failed', 'RATE_LIMITED'], [$sync[$failed->id]['status'], $sync[$failed->id]['last_error_code']]);
        $this->assertNotNull($sync[$failed->id]['last_attempt_at']);
        $this->assertSame(['failed', 'ACCOUNT_NOT_CONNECTED'], [$sync[$expired->id]['status'], $sync[$expired->id]['last_error_code']]);
        $this->assertSame([], $this->analytics()->json('data.points'), 'Aucune donnée collectée : aucun point, aucun chiffre inventé.');
    }

    public function test_manual_sync_is_refused_without_adapter_or_connection_and_dispatched_otherwise(): void
    {
        Queue::fake();
        $post = fn () => $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/analytics/sync', ['social_account_id' => $this->account->id]);
        $post()->assertStatus(409)->assertJsonPath('errors.code.0', 'ANALYTICS_UNSUPPORTED');

        config(['analytics.adapters' => [$this->account->platform->key => FakeMetricsAdapter::class]]);
        $variant = $this->variant($this->account);
        $post()->assertStatus(202)->assertJsonPath('data.dispatched', 2);
        Queue::assertPushedOn('analytics', SyncAccountMetricsJob::class);
        Queue::assertPushedOn('analytics', SyncPostMetricsJob::class, fn ($job) => $job->postVariantId === $variant->id);

        $this->account->forceFill(['status' => 'revoked'])->save();
        $post()->assertStatus(409)->assertJsonPath('errors.code.0', 'ACCOUNT_NOT_CONNECTED');
    }

    public function test_posts_block_names_only_the_users_posts_present_in_the_points(): void
    {
        $youtube = $this->variant($this->account);
        $youtube->forceFill(['options' => ['title' => 'Titre YouTube', 'youtubeVisibility' => 'public'], 'external_url' => 'https://www.youtube.com/watch?v=abc'])->save();
        $titled = $this->variant($this->account);
        $titled->post->forceFill(['title' => 'Titre interne'])->save();
        $bodyOnly = $this->variant($this->account);
        $bodyOnly->post->forceFill(['title' => null, 'body' => "Première ligne\nSuite"])->save();
        $withoutPoints = $this->variant($this->account);
        $foreign = $this->variant(SocialAccount::factory()->for(User::factory()->create())->create());
        foreach ([$youtube, $titled, $bodyOnly, $foreign] as $variant) {
            $this->metric($variant->socialAccount, '2026-10-14', ['views' => 1], $variant, 'lifetime');
        }

        $posts = collect($this->analytics(['from' => '2026-10-14', 'to' => '2026-10-14'])->assertOk()->json('data.posts'))->keyBy('post_variant_id');

        $this->assertEqualsCanonicalizing([$youtube->id, $titled->id, $bodyOnly->id], $posts->keys()->all(), 'Ni publication sans relevé, ni publication d\'un autre utilisateur.');
        $this->assertSame(['Titre YouTube', 'https://www.youtube.com/watch?v=abc'], [$posts[$youtube->id]['title'], $posts[$youtube->id]['external_url']]);
        $this->assertSame('Titre interne', $posts[$titled->id]['title']);
        $this->assertSame('Première ligne', $posts[$bodyOnly->id]['title']);
        $this->assertNotNull($posts[$youtube->id]['published_at']);
        $this->assertSame([$this->account->platform->key, $this->account->id], [$posts[$youtube->id]['platform'], $posts[$youtube->id]['social_account_id']]);
    }

    public function test_youtube_points_keep_null_counters_and_the_network_filter_applies(): void
    {
        $youtube = SocialAccount::factory()->for($this->user)->create(['social_platform_id' => SocialPlatform::factory()->create(['key' => 'youtube', 'name' => 'YouTube'])->id]);
        $video = $this->variant($youtube);
        $this->metric($youtube, '2026-10-14', ['views' => 7, 'likes' => 1, 'comments' => 1], $video, 'lifetime');
        $this->metric($youtube, '2026-10-14', ['views' => 0, 'followers' => 1], null, 'lifetime');
        $this->metric($this->account, '2026-10-14', ['impressions' => 5]);

        $res = $this->analytics(['from' => '2026-10-14', 'to' => '2026-10-14', 'platform' => 'youtube'])->assertOk();

        $points = collect($res->json('data.points'));
        $this->assertSame([$youtube->id], $points->pluck('social_account_id')->unique()->values()->all(), 'Filtre réseau.');
        $post = $points->firstWhere('post_variant_id', $video->id);
        $this->assertSame([7, 1, 1, null, null], [$post['views'], $post['likes'], $post['comments'], $post['impressions'], $post['followers']]);
        $account = $points->firstWhere('post_variant_id', null);
        $this->assertSame([0, 1, null, null], [$account['views'], $account['followers'], $account['likes'], $account['comments']], 'Chaîne : J\'aime et commentaires non fournis → null.');
        $this->assertSame([$video->id], array_column($res->json('data.posts'), 'post_variant_id'));
        $this->assertSame(['youtube'], array_column($res->json('data.sync'), 'platform'));
    }

    private function variant(SocialAccount $account): PostVariant
    {
        $post = Post::factory()->for($account->user)->create();
        $variant = new PostVariant(['social_account_id' => $account->id, 'format' => 'feed']);
        $variant->post_id = $post->id;
        $variant->forceFill(['status' => 'published', 'external_post_id' => 'ext-'.uniqid(), 'published_at' => now()->subDay()])->save();

        return $variant;
    }
}
