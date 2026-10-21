<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalyticsSyncService;
use App\Domain\Analytics\Enums\SyncStatus;
use App\Jobs\SyncAccountMetricsJob;
use App\Jobs\SyncPostMetricsJob;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Statistiques YouTube (étape 7, P2 — ADR-064) : Data API v3 simulée (Http::fake, aucun appel réel), collecte
 * par le socle P1 (service, jobs, état), NULL ≠ 0, jeton via YouTubeTokenManager, isolation par chaîne et par
 * utilisateur, jamais de jeton dans la réponse conservée, les messages ou les journaux.
 */
class YouTubeMetricsTest extends TestCase
{
    use RefreshDatabase, YouTubeFixtures;

    private const VIDEO_ID = 'vid_test_001';

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->account = $this->youtubeAccount($this->user);
    }

    private function sync(): AnalyticsSyncService
    {
        return app(AnalyticsSyncService::class);
    }

    private function video(array $statistics, string $channelId = 'UC_test_channel', string $id = self::VIDEO_ID): array
    {
        return ['items' => [['id' => $id, 'snippet' => ['channelId' => $channelId], 'statistics' => $statistics]]];
    }

    private function googleError(int $status, string $reason): PromiseInterface
    {
        return Http::response(['error' => ['code' => $status, 'message' => 'x', 'errors' => [['reason' => $reason]]]], $status);
    }

    private function variant(?SocialAccount $account = null, string $videoId = self::VIDEO_ID): PostVariant
    {
        $account ??= $this->account;
        $post = Post::factory()->for($account->user)->create();
        $variant = new PostVariant(['social_account_id' => $account->id, 'format' => 'feed']);
        $variant->post_id = $post->id;
        $variant->forceFill(['status' => 'published', 'external_post_id' => $videoId, 'published_at' => now()->subDays(3)])->save();

        return $variant;
    }

    private function state(?PostVariant $variant): AnalyticsSyncState
    {
        return AnalyticsSyncState::query()->where('social_account_id', $this->account->id)
            ->where('post_variant_id', $variant?->id)->sole();
    }

    public function test_video_statistics_are_collected_read_only_as_a_dated_lifetime_point(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response($this->video(['viewCount' => '1520', 'likeCount' => '87', 'favoriteCount' => '0', 'commentCount' => '12']))]);
        $variant = $this->variant();

        (new SyncPostMetricsJob($variant->id))->handle($this->sync());

        $metric = AnalyticsMetric::sole();
        $this->assertSame([1520, 87, 12], [(int) $metric->views, (int) $metric->likes, (int) $metric->comments]);
        $this->assertSame([$variant->id, 'lifetime', now('UTC')->toDateString()], [$metric->post_variant_id, $metric->granularity->value, $metric->metric_date->toDateString()]);
        foreach (['impressions', 'reach', 'engagements', 'clicks', 'shares', 'saves', 'followers'] as $notProvided) {
            $this->assertNull($metric->{$notProvided}, "{$notProvided} n'est pas fourni par YouTube : null, jamais 0.");
        }
        $this->assertSame(SyncStatus::Succeeded, $this->state($variant)->status);
        $this->assertSame('youtube-data-v3', AnalyticsSnapshot::sole()->api_version);

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && str_starts_with($r->url(), 'https://www.googleapis.com/youtube/v3/videos?')
            && $r['id'] === self::VIDEO_ID && $r['part'] === 'statistics,snippet'
            && $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, json_encode(AnalyticsSnapshot::sole()->payload));
    }

    public function test_hidden_likes_and_disabled_comments_stay_null_never_zero(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::response($this->video(['viewCount' => '40', 'favoriteCount' => '0']))]);

        $this->sync()->syncPost($this->variant()->id);

        $metric = AnalyticsMetric::sole();
        $this->assertSame(40, (int) $metric->views);
        $this->assertNull($metric->likes, 'J\'aime masqués : non fournis.');
        $this->assertNull($metric->comments, 'Commentaires désactivés : non fournis.');
    }

    public function test_a_new_collection_the_same_day_updates_the_point_without_duplicating_it(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::sequence()
            ->push($this->video(['viewCount' => '10', 'likeCount' => '1', 'commentCount' => '0']))
            ->push($this->video(['viewCount' => '25', 'likeCount' => '3', 'commentCount' => '0']))
            ->push($this->video(['viewCount' => '31', 'likeCount' => '3', 'commentCount' => '1']))]);
        $variant = $this->variant();

        $this->sync()->syncPost($variant->id);
        $this->sync()->syncPost($variant->id);

        $metric = AnalyticsMetric::sole();
        $this->assertSame([25, 3, 0], [(int) $metric->views, (int) $metric->likes, (int) $metric->comments], 'Un vrai 0 reste 0.');
        $this->assertSame(2, AnalyticsSnapshot::count());

        // Le lendemain : nouveau point cumulé, le précédent est conservé.
        $this->travel(1)->days();
        SocialToken::query()->update(['expires_at' => now()->addHour()]); // jeton encore valide le lendemain
        $this->sync()->syncPost($variant->id);
        $this->assertSame([25, 31], AnalyticsMetric::query()->orderBy('metric_date')->pluck('views')->map(fn ($v) => (int) $v)->all());
    }

    public function test_channel_statistics_give_followers_and_views_and_hidden_subscribers_stay_null(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::sequence()
            ->push(['items' => [['id' => 'UC_test_channel', 'statistics' => ['viewCount' => '9000', 'subscriberCount' => '120', 'hiddenSubscriberCount' => false, 'videoCount' => '14']]]])
            ->push(['items' => [['id' => 'UC_test_channel', 'statistics' => ['viewCount' => '9100', 'subscriberCount' => '0', 'hiddenSubscriberCount' => true, 'videoCount' => '14']]]])]);

        $this->sync()->syncAccount($this->account->id);
        $metric = AnalyticsMetric::sole();
        $this->assertSame([9000, 120, null, 'lifetime'], [(int) $metric->views, (int) $metric->followers, $metric->post_variant_id, $metric->granularity->value]);
        $this->assertSame(['video_count' => '14'], $metric->extra);
        Http::assertSent(fn (Request $r) => $r['mine'] === 'true' && $r['part'] === 'statistics');

        $this->sync()->syncAccount($this->account->id);
        $this->assertNull(AnalyticsMetric::sole()->followers, 'Abonnés masqués par la chaîne : null, pas le 0 renvoyé par l\'API.');
        $this->assertSame(9100, (int) AnalyticsMetric::sole()->views);
    }

    public function test_an_expiring_token_is_renewed_by_the_token_manager_before_reading(): void
    {
        SocialToken::query()->update(['expires_at' => now()->addMinute()]);
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.renewed-for-analytics', 'expires_in' => 3599]),
            'www.googleapis.com/youtube/v3/videos*' => Http::response($this->video(['viewCount' => '5'])),
        ]);

        $this->sync()->syncPost($this->variant()->id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/token') && $r['grant_type'] === 'refresh_token');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/youtube/v3/videos') && $r->hasHeader('Authorization', 'Bearer ya29.renewed-for-analytics'));
        $this->assertSame(5, (int) AnalyticsMetric::sole()->views);
    }

    public function test_a_refused_renewal_stops_collection_and_asks_for_reconnection_without_retry_loop(): void
    {
        SocialToken::query()->update(['expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $variant = $this->variant();

        $state = $this->sync()->syncPost($variant->id);

        $this->assertSame([SyncStatus::Failed, 'TOKEN_REVOKED', null], [$state->status, $state->last_error_code, $state->next_attempt_at]);
        $this->assertSame('expired', $this->account->fresh()->status->value);
        $this->assertSame(0, AnalyticsMetric::count());

        // Plus aucun appel à Google tant que l'utilisateur ne s'est pas reconnecté.
        $this->assertSame('ACCOUNT_NOT_CONNECTED', $this->sync()->syncAccount($this->account->id)->last_error_code);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/youtube/v3/'));
    }

    public function test_a_rejected_token_is_renewed_once_then_the_failure_is_definitive(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.second', 'expires_in' => 3599]),
            'www.googleapis.com/youtube/v3/videos*' => $this->googleError(401, 'authError'),
        ]);

        $state = $this->sync()->syncPost($this->variant()->id);

        $this->assertSame([SyncStatus::Failed, 'TOKEN_INVALID', null], [$state->status, $state->last_error_code, $state->next_attempt_at]);
        Http::assertSentCount(3); // appel, un seul renouvellement forcé, un seul nouvel essai
    }

    public function test_insufficient_permissions_fail_definitively_and_keep_the_last_valid_values(): void
    {
        $variant = $this->variant();
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::sequence()
            ->push($this->video(['viewCount' => '70', 'likeCount' => '4', 'commentCount' => '2']))
            ->pushResponse($this->googleError(403, 'insufficientPermissions'))]);
        $this->sync()->syncPost($variant->id);

        $state = $this->sync()->syncPost($variant->id);

        $this->assertSame([SyncStatus::Failed, 'PERMISSION_DENIED', null], [$state->status, $state->last_error_code, $state->next_attempt_at]);
        $this->assertStringContainsString('Reconnectez', $state->last_error_message);
        $this->assertSame(70, (int) AnalyticsMetric::sole()->views, 'Les dernières valeurs valides restent intactes.');
        $this->assertNotNull($state->last_success_at);
    }

    public function test_quota_exhaustion_and_outages_are_retried_later_without_tokens_in_messages_or_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC')); // 03:00 heure du Pacifique
        SocialToken::query()->update(['expires_at' => now()->addHour()]);
        $variant = $this->variant();
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::sequence()
            ->pushResponse($this->googleError(403, 'quotaExceeded'))
            ->push('', 503)
            ->pushFailedConnection()]);

        $state = $this->sync()->syncPost($variant->id);
        $this->assertSame([SyncStatus::Failed, 'QUOTA_EXCEEDED'], [$state->status, $state->last_error_code]);
        $this->assertTrue($state->next_attempt_at->gte(CarbonImmutable::parse('2026-10-15 00:00:00', 'America/Los_Angeles')), 'Pas de nouvel essai avant le renouvellement du quota.');

        $this->assertSame('PLATFORM_UNAVAILABLE', $this->sync()->syncPost($variant->id)->last_error_code);
        $state = $this->sync()->syncPost($variant->id);
        $this->assertSame(['NETWORK_ERROR', 3], [$state->last_error_code, $state->consecutive_failures]);
        $this->assertNotNull($state->next_attempt_at, 'Échec temporaire : nouvel essai différé.');
        $this->assertSame(0, AnalyticsMetric::count());

        $traces = implode("\n", [...$logged, json_encode(AnalyticsSyncState::all()->toArray()), json_encode(AnalyticsSnapshot::all()->toArray())]);
        $this->assertNotEmpty($logged);
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, $traces);
        $this->assertStringNotContainsString(self::REFRESH_TOKEN, $traces);
    }

    public function test_a_video_missing_or_belonging_to_another_channel_is_never_collected(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/videos*' => Http::sequence()
            ->push(['items' => []])
            ->push($this->video(['viewCount' => '999999'], channelId: 'UC_someone_else'))]);
        $variant = $this->variant();

        $this->assertSame([SyncStatus::Unsupported, 'VIDEO_NOT_FOUND'], [$this->sync()->syncPost($variant->id)->status, $this->state($variant)->last_error_code]);
        $this->assertSame([SyncStatus::Unsupported, 'VIDEO_NOT_OWNED'], [$this->sync()->syncPost($variant->id)->status, $this->state($variant)->last_error_code]);
        $this->assertSame(0, AnalyticsMetric::count());
        $this->assertSame(0, AnalyticsSnapshot::count());
    }

    public function test_scheduled_and_manual_syncs_cover_only_the_owners_real_youtube_accounts(): void
    {
        Queue::fake();
        $variant = $this->variant();
        $demo = $this->youtubeAccount(User::factory()->create());
        $demo->forceFill(['metadata' => ['demo' => true]])->save();
        $other = User::factory()->create();
        $foreign = $this->youtubeAccount($other);

        $this->artisan('analytics:sync')->assertSuccessful();
        Queue::assertPushedOn('analytics', SyncAccountMetricsJob::class, fn ($job) => $job->socialAccountId === $this->account->id);
        Queue::assertPushedOn('analytics', SyncPostMetricsJob::class, fn ($job) => $job->postVariantId === $variant->id);
        Queue::assertNotPushed(SyncAccountMetricsJob::class, fn ($job) => $job->socialAccountId === $demo->id);

        $sync = fn (SocialAccount $a) => $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/analytics/sync', ['social_account_id' => $a->id]);
        $sync($this->account)->assertStatus(202)->assertJsonPath('data.dispatched', 2);
        $sync($foreign)->assertNotFound();

        AnalyticsMetric::query()->create(['social_account_id' => $foreign->id, 'metric_date' => now()->toDateString(), 'granularity' => 'lifetime', 'views' => 5]);
        $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/analytics?platform=youtube')->assertOk()->assertJsonPath('data.points', [])
            ->assertJsonPath('data.sync.0.status', 'pending');
    }
}
