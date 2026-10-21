<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\AnalyticsSyncService;
use App\Domain\Analytics\Enums\SyncStatus;
use App\Models\AnalyticsMetric;
use App\Models\AnalyticsSnapshot;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\LinkedInFixtures;
use Tests\TestCase;

/**
 * Statistiques LinkedIn (étape 7, P3 — ADR-067) : memberCreatorPostAnalytics simulé (Http::fake). Sans le scope
 * r_member_postAnalytics, aucun appel ; avec, une requête Rest.li 2.0 par métrique, versionnée.
 */
class LinkedInMetricsTest extends TestCase
{
    use LinkedInFixtures, RefreshDatabase;

    private const SHARE = 'urn:li:share:7513192488166268928';

    private const ANALYTICS_SCOPES = ['openid', 'profile', 'email', 'w_member_social', 'r_member_postAnalytics'];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
    }

    private function sync(): AnalyticsSyncService
    {
        return app(AnalyticsSyncService::class);
    }

    private function variant(SocialAccount $account, string $urn = self::SHARE): PostVariant
    {
        $post = Post::factory()->for($this->user)->create();
        $variant = new PostVariant(['social_account_id' => $account->id, 'format' => 'feed']);
        $variant->post_id = $post->id;
        $variant->forceFill(['status' => 'published', 'external_post_id' => $urn, 'published_at' => now()->subDays(8)])->save();

        return $variant;
    }

    /** Faux LinkedIn : un compte par queryType ; null → aucun élément renvoyé. */
    private function fakeLinkedIn(array $counts): void
    {
        Http::fake(function (Request $request) use ($counts) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $type = $query['queryType'] ?? '';
            $count = $counts[$type] ?? null;

            return Http::response(['elements' => $count === null ? [] : [['count' => $count, 'metricType' => $type, 'targetEntity' => ['share' => self::SHARE]]], 'paging' => ['count' => 10, 'start' => 0, 'links' => []]]);
        });
    }

    public function test_without_the_analytics_scope_nothing_is_called_and_the_reason_is_explicit(): void
    {
        $account = $this->linkedinAccount($this->user); // scopes actuels : w_member_social seulement
        $variant = $this->variant($account);

        $this->assertTrue($this->sync()->supports($account), 'Adaptateur déclaré : la raison réelle est affichée, pas « réseau non pris en charge ».');
        foreach ([$this->sync()->syncAccount($account->id), $this->sync()->syncPost($variant->id)] as $state) {
            $this->assertSame([SyncStatus::Unsupported, 'PERMISSION_REQUIRED'], [$state->status, $state->last_error_code]);
            $this->assertStringContainsString('r_member_postAnalytics', $state->last_error_message);
            $this->assertNotNull($state->next_attempt_at, 'Revérifié chaque jour : la collecte démarre seule après approbation et reconnexion.');
        }
        Http::assertNothingSent();
        $this->assertSame(0, AnalyticsMetric::count());

        $sync = collect($this->actingAs($this->user)->fromSpa()->getJson('/api/v1/analytics')->assertOk()->json('data.sync'))->firstWhere('social_account_id', $account->id);
        $this->assertSame(['unsupported', 'PERMISSION_REQUIRED'], [$sync['status'], $sync['last_error_code']]);
    }

    public function test_post_metrics_use_one_versioned_restli_request_per_metric_and_keep_missing_values_null(): void
    {
        $account = $this->linkedinAccount($this->user, ['scopes' => self::ANALYTICS_SCOPES]);
        $this->fakeLinkedIn(['IMPRESSION' => 1250, 'MEMBERS_REACHED' => 800, 'REACTION' => 37, 'COMMENT' => 0, 'RESHARE' => 2, 'LINK_CLICKS' => 11]);

        $state = $this->sync()->syncPost($this->variant($account)->id);

        $this->assertSame(SyncStatus::Succeeded, $state->status);
        $metric = AnalyticsMetric::sole();
        $this->assertSame([1250, 800, 37, 0, 2, 11], [(int) $metric->impressions, (int) $metric->reach, (int) $metric->likes, (int) $metric->comments, (int) $metric->shares, (int) $metric->clicks]);
        $this->assertNull($metric->saves, 'POST_SAVE non renvoyé : null, jamais 0.');
        $this->assertNull($metric->views);
        $this->assertSame('lifetime', $metric->granularity->value);

        Http::assertSentCount(7);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.linkedin.com/rest/memberCreatorPostAnalytics?q=entity&entity=(share:urn%3Ali%3Ashare%3A7513192488166268928)&queryType=IMPRESSION&aggregation=TOTAL')
            && $r->hasHeader('Linkedin-Version', '202609') && $r->hasHeader('X-Restli-Protocol-Version', '2.0.0')
            && $r->hasHeader('Authorization', 'Bearer '.self::LI_ACCESS));
        $this->assertSame('202609', AnalyticsSnapshot::sole()->api_version);
        $this->assertStringNotContainsString(self::LI_ACCESS, AnalyticsSnapshot::sole()->toJson());
    }

    public function test_ugc_posts_use_the_ugc_entity_key(): void
    {
        $account = $this->linkedinAccount($this->user, ['scopes' => self::ANALYTICS_SCOPES]);
        $this->fakeLinkedIn(['IMPRESSION' => 5]);

        $this->sync()->syncPost($this->variant($account, 'urn:li:ugcPost:6443156446455693312')->id);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'entity=(ugc:urn%3Ali%3AugcPost%3A6443156446455693312)'));
    }

    public function test_account_metrics_aggregate_impressions_and_reach_only_followers_stay_null(): void
    {
        $account = $this->linkedinAccount($this->user, ['scopes' => self::ANALYTICS_SCOPES]);
        $this->fakeLinkedIn(['IMPRESSION' => 9000, 'MEMBERS_REACHED' => 4100, 'REACTION' => 70]);

        $this->sync()->syncAccount($account->id);

        $metric = AnalyticsMetric::sole();
        $this->assertSame([9000, 4100, null], [(int) $metric->impressions, (int) $metric->reach, $metric->post_variant_id]);
        $this->assertNull($metric->likes, 'Réactions agrégées non demandées (incohérentes avec l\'interface LinkedIn selon sa documentation).');
        $this->assertNull($metric->followers);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '?q=me&queryType=MEMBERS_REACHED&aggregation=TOTAL'));
    }

    public function test_an_unrecognised_post_identifier_is_never_sent(): void
    {
        $account = $this->linkedinAccount($this->user, ['scopes' => self::ANALYTICS_SCOPES]);

        $state = $this->sync()->syncPost($this->variant($account, 'urn:li:share:12)&q=me')->id);

        $this->assertSame([SyncStatus::Unsupported, 'POST_NOT_FOUND'], [$state->status, $state->last_error_code]);
        Http::assertNothingSent();
    }

    public function test_linkedin_errors_map_to_the_sync_contract(): void
    {
        $account = $this->linkedinAccount($this->user, ['scopes' => self::ANALYTICS_SCOPES]);
        $variant = $this->variant($account);
        $cases = [
            [403, ['message' => 'Not enough permissions'], SyncStatus::Unsupported, 'PERMISSION_REQUIRED', false],
            [426, ['code' => 'NONEXISTENT_VERSION', 'message' => 'Requested version 202510 is not active'], SyncStatus::Failed, 'API_VERSION_UNSUPPORTED', false],
            [429, ['message' => 'Too many requests'], SyncStatus::Failed, 'RATE_LIMITED', true],
            [500, ['message' => 'Internal error'], SyncStatus::Failed, 'PLATFORM_UNAVAILABLE', true],
            [400, ['message' => 'Unsupported query type'], SyncStatus::Failed, 'INVALID_REQUEST', false],
        ];
        $current = null;
        Http::fake(function () use (&$current) {
            return Http::response($current[1], $current[0]);
        });
        foreach ($cases as [$status, $body, $expectedStatus, $code, $retried]) {
            AnalyticsSyncState::query()->delete();
            $current = [$status, $body];
            $state = $this->sync()->syncPost($variant->id);
            $this->assertSame([$expectedStatus, $code], [$state->status, $state->last_error_code], "HTTP {$status}");
            if ($expectedStatus === SyncStatus::Failed) {
                $this->assertSame($retried, $state->next_attempt_at !== null, "HTTP {$status} : nouvelle tentative");
            }
        }
        $this->assertSame('connected', $account->fresh()->status->value);
    }

    public function test_a_rejected_token_marks_the_account_for_reconnection(): void
    {
        $account = $this->linkedinAccount($this->user, ['scopes' => self::ANALYTICS_SCOPES]);
        Http::fake(fn () => Http::response(['serviceErrorCode' => 65600, 'message' => 'Invalid access token', 'status' => 401], 401));

        $state = $this->sync()->syncAccount($account->id);

        $this->assertSame([SyncStatus::Failed, 'TOKEN_INVALID', null], [$state->status, $state->last_error_code, $state->next_attempt_at]);
        $this->assertSame('expired', $account->fresh()->status->value);
        Http::assertSentCount(1);
    }
}
