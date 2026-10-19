<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Adapters\XMetricsAdapter;
use App\Domain\Analytics\AnalyticsSyncService;
use App\Domain\Analytics\Enums\SyncStatus;
use App\Jobs\SyncAccountMetricsJob;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Social\Concerns\XFixtures;
use Tests\TestCase;

/**
 * Statistiques X (étape 7, P3 — ADR-067) : API v2 simulée (Http::fake, aucun appel réel ni facturé).
 * Adaptateur inactif par défaut (lectures facturées), activé ici explicitement par la configuration.
 */
class XMetricsTest extends TestCase
{
    use RefreshDatabase, XFixtures;

    private const POST_ID = '1846000000000000001';

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->account = $this->xAccount($this->user);
    }

    private function enable(): void
    {
        config(['analytics.adapters.x' => XMetricsAdapter::class]);
    }

    private function sync(): AnalyticsSyncService
    {
        return app(AnalyticsSyncService::class);
    }

    private function variant(int $daysAgo = 3, string $postId = self::POST_ID): PostVariant
    {
        $post = Post::factory()->for($this->user)->create();
        $variant = new PostVariant(['social_account_id' => $this->account->id, 'format' => 'feed']);
        $variant->post_id = $post->id;
        $variant->forceFill(['status' => 'published', 'external_post_id' => $postId, 'published_at' => now()->subDays($daysAgo)])->save();

        return $variant;
    }

    private function tweet(array $public, array $nonPublic = [], string $author = self::X_USER_ID): array
    {
        return ['data' => array_filter(['id' => self::POST_ID, 'author_id' => $author, 'public_metrics' => $public, 'non_public_metrics' => $nonPublic ?: null])];
    }

    public function test_the_adapter_is_inactive_by_default_and_never_calls_x(): void
    {
        $this->assertNull(config('analytics.adapters.x'), 'ANALYTICS_X_ENABLED absent : X non déclaré.');
        Queue::fake();
        $this->variant();

        $this->artisan('analytics:sync')->assertSuccessful();
        Queue::assertNotPushed(SyncAccountMetricsJob::class, fn ($job) => $job->socialAccountId === $this->account->id);
        $state = $this->sync()->syncAccount($this->account->id);

        $this->assertSame([SyncStatus::Unsupported, 'NO_ADAPTER'], [$state->status, $state->last_error_code]);
        Http::assertNothingSent();
    }

    public function test_account_metrics_give_followers_and_keep_other_counters_in_extra(): void
    {
        $this->enable();
        Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => ['id' => self::X_USER_ID, 'username' => 'ada',
            'public_metrics' => ['followers_count' => 42, 'following_count' => 10, 'tweet_count' => 7, 'listed_count' => 0, 'like_count' => 3]]])]);

        $state = $this->sync()->syncAccount($this->account->id);

        $this->assertSame(SyncStatus::Succeeded, $state->status);
        $metric = AnalyticsMetric::sole();
        $this->assertSame([42, 'lifetime', null], [(int) $metric->followers, $metric->granularity->value, $metric->impressions]);
        $this->assertEquals(['following_count' => 10, 'post_count' => 7, 'listed_count' => 0], $metric->extra);
        Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r['user.fields'] === 'public_metrics' && $r->hasHeader('Authorization', 'Bearer '.self::X_ACCESS));
    }

    public function test_another_x_account_behind_the_token_is_never_recorded(): void
    {
        $this->enable();
        Http::fake(['api.x.com/2/users/me*' => Http::response(['data' => ['id' => '999', 'public_metrics' => ['followers_count' => 5000]]])]);

        $state = $this->sync()->syncAccount($this->account->id);

        $this->assertSame([SyncStatus::Unsupported, 'ACCOUNT_MISMATCH'], [$state->status, $state->last_error_code]);
        $this->assertSame(0, AnalyticsMetric::count());
    }

    public function test_a_recent_post_collects_public_and_non_public_metrics_and_missing_values_stay_null(): void
    {
        $this->enable();
        Http::fake(['api.x.com/2/tweets/*' => Http::response($this->tweet(
            ['retweet_count' => 2, 'reply_count' => 0, 'like_count' => 9, 'quote_count' => 1, 'impression_count' => 310],
            ['url_link_clicks' => 4, 'user_profile_clicks' => 3, 'engagements' => 19],
        ))]);
        $variant = $this->variant(daysAgo: 3);

        $this->sync()->syncPost($variant->id);

        $metric = AnalyticsMetric::sole();
        $this->assertSame([310, 9, 0, 2, 4, 19], [(int) $metric->impressions, (int) $metric->likes, (int) $metric->comments, (int) $metric->shares, (int) $metric->clicks, (int) $metric->engagements]);
        $this->assertNull($metric->saves, 'bookmark_count absent : null, jamais 0.');
        $this->assertNull($metric->reach);
        $this->assertEquals(['quotes' => 1, 'profile_clicks' => 3], $metric->extra);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/2/tweets/'.self::POST_ID) && $r['tweet.fields'] === 'public_metrics,non_public_metrics,author_id');
    }

    public function test_an_older_post_only_requests_public_metrics(): void
    {
        $this->enable();
        Http::fake(['api.x.com/2/tweets/*' => Http::response($this->tweet(['like_count' => 1, 'impression_count' => 50, 'bookmark_count' => 0]))]);

        $this->sync()->syncPost($this->variant(daysAgo: 45)->id);

        Http::assertSent(fn (Request $r) => $r['tweet.fields'] === 'public_metrics,author_id');
        $metric = AnalyticsMetric::sole();
        $this->assertSame([50, 1, 0], [(int) $metric->impressions, (int) $metric->likes, (int) $metric->saves]);
        $this->assertNull($metric->clicks, 'Plus de 30 jours : X ne fournit plus les clics → non fournis.');
        $this->assertNull($metric->engagements);
    }

    public function test_a_post_by_another_author_or_missing_is_not_collected(): void
    {
        $this->enable();
        Http::fake(['api.x.com/2/tweets/*' => Http::sequence()
            ->push($this->tweet(['like_count' => 999], author: '777'))
            ->push(['errors' => [['title' => 'Not Found Error', 'type' => 'https://api.twitter.com/2/problems/resource-not-found']]])]);
        $variant = $this->variant();

        $this->assertSame('POST_NOT_OWNED', $this->sync()->syncPost($variant->id)->last_error_code);
        $this->assertSame('POST_NOT_FOUND', $this->sync()->syncPost($variant->id)->last_error_code);
        $this->assertSame(0, AnalyticsMetric::count());
    }

    public function test_no_api_access_or_credits_is_a_definitive_failure_without_retry(): void
    {
        $this->enable();
        Http::fake(['api.x.com/2/users/me*' => Http::response(['title' => 'Payment Required', 'type' => 'https://api.x.com/2/problems/credits', 'detail' => 'No credits'], 402)]);

        $state = $this->sync()->syncAccount($this->account->id);

        $this->assertSame([SyncStatus::Failed, 'API_ACCESS_DENIED', null], [$state->status, $state->last_error_code, $state->next_attempt_at]);
        $this->assertSame('connected', $this->account->fresh()->status->value, 'Problème d\'offre X, pas de compte : le compte reste connecté.');
    }

    public function test_a_rejected_token_is_renewed_once_then_the_account_must_reconnect(): void
    {
        $this->enable();
        Http::fake([
            'api.x.com/2/oauth2/token' => Http::response(['access_token' => 'x-renewed', 'refresh_token' => 'x-refresh-2', 'expires_in' => 7200, 'token_type' => 'bearer', 'scope' => 'users.read tweet.read tweet.write offline.access']),
            'api.x.com/2/users/me*' => Http::response(['title' => 'Unauthorized', 'type' => 'about:blank', 'status' => 401], 401),
        ]);

        $state = $this->sync()->syncAccount($this->account->id);

        $this->assertSame([SyncStatus::Failed, 'TOKEN_INVALID'], [$state->status, $state->last_error_code]);
        Http::assertSentCount(3); // lecture, renouvellement forcé, une seule nouvelle lecture
        $this->assertSame('expired', $this->account->fresh()->status->value);
        $this->assertSame('ACCOUNT_NOT_CONNECTED', $this->sync()->syncAccount($this->account->id)->last_error_code);
        Http::assertSentCount(3);
    }

    public function test_rate_limit_waits_for_the_announced_reset_and_tokens_never_leak(): void
    {
        $this->enable();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.json_encode($e->context);
        });
        $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
        SocialToken::query()->update(['expires_at' => now()->addHours(2)]);
        $reset = now()->addMinutes(40)->getTimestamp();
        Http::fake(['api.x.com/2/users/me*' => Http::response(['title' => 'Too Many Requests'], 429, ['x-rate-limit-reset' => (string) $reset])]);

        $state = $this->sync()->syncAccount($this->account->id);

        $this->assertSame([SyncStatus::Failed, 'RATE_LIMITED'], [$state->status, $state->last_error_code]);
        $this->assertTrue($state->next_attempt_at->gte(CarbonImmutable::createFromTimestamp($reset)));
        $traces = implode("\n", [...$logged, AnalyticsSyncState::all()->toJson(), AnalyticsSnapshot::all()->toJson()]);
        $this->assertStringNotContainsString(self::X_ACCESS, $traces);
        $this->assertStringNotContainsString(self::X_REFRESH, $traces);
    }
}
