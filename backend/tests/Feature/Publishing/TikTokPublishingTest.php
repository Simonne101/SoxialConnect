<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\PublishingService;
use App\Jobs\PublishPostVariantJob;
use App\Jobs\ReconcilePublicationJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Social\Concerns\TikTokFixtures;
use Tests\TestCase;

/**
 * TikTok dans le moteur : PublisherRegistry → TikTokPublisher → (faux) Content Posting API →
 * Processing(publish_id) → ReconcilePublicationJob → PUBLISH_COMPLETE / FAILED. Aucun appel réel.
 */
class TikTokPublishingTest extends TestCase
{
    use RefreshDatabase, TikTokFixtures;

    private User $user;

    private SocialAccount $account;

    private Media $video;

    /** @var list<array{range: string, length: string, size: int}> */
    private array $chunks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->account = $this->tiktokAccount($this->user);
        $this->video = $this->tiktokVideo($this->user);
    }

    /**
     * Faux TikTok. $init / $status : réponses JSON (ou callables) ; $upload : callable(int $index).
     */
    private function fakeTikTok(array $creator = [], mixed $init = null, ?callable $upload = null, mixed $status = null): void
    {
        $this->chunks = [];
        Http::fake(function (Request $r) use ($creator, $init, $upload, $status) {
            $url = $r->url();
            if (str_contains($url, '/creator_info/query/')) {
                return Http::response($this->creatorInfo($creator));
            }
            if (str_contains($url, '/video/init/')) {
                if (is_callable($init)) {
                    return $init($r);
                }

                return $init ?? Http::response(['data' => ['publish_id' => 'v_pub_file~v2.123', 'upload_url' => self::TT_UPLOAD_URL], 'error' => ['code' => 'ok']]);
            }
            if ($r->method() === 'PUT' && $url === self::TT_UPLOAD_URL) {
                $index = count($this->chunks);
                $this->chunks[] = ['range' => $r->header('Content-Range')[0] ?? '', 'length' => $r->header('Content-Length')[0] ?? '', 'size' => strlen($r->body())];
                if ($upload) {
                    return $upload($index);
                }
                $last = str_ends_with($r->header('Content-Range')[0], '/'.$this->video->size_bytes)
                    && (int) explode('-', explode(' ', $r->header('Content-Range')[0])[1])[1] === $this->video->size_bytes - 1;

                return Http::response('', $last ? 201 : 206);
            }
            if (str_contains($url, '/status/fetch/')) {
                return is_callable($status) ? $status($r) : ($status ?? Http::response(['data' => ['status' => 'PROCESSING_UPLOAD'], 'error' => ['code' => 'ok']]));
            }

            return null;
        });
    }

    private function createPost(array $options = [], ?array $mediaIds = null): Post
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => 'Ma vidéo TikTok #tuto',
            'media_ids' => $mediaIds ?? [$this->video->id],
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'video', 'options' => array_merge(['privacy' => 'private', 'hashtags' => ['tuto', 'laravel']], $options)]],
        ])->assertCreated()->json('data.id');

        return Post::findOrFail($id);
    }

    private function publishAndRun(Post $post, int $jobIndex = 0): void
    {
        app(PublishingService::class)->publishNow($this->user, $post);
        Queue::pushed(PublishPostVariantJob::class)->values()[$jobIndex]->handle(app(PublishingService::class));
    }

    private function runLastReconcile(): void
    {
        Queue::pushed(ReconcilePublicationJob::class)->last()->handle(app(PublishingService::class));
    }

    private function variant(Post $post): PostVariant
    {
        return $post->variants()->firstOrFail();
    }

    private function initSent(): bool
    {
        return collect(Http::recorded())->contains(fn ($pair) => str_contains($pair[0]->url(), '/video/init/'));
    }

    // --- Parcours complet ------------------------------------------------------------------------

    public function test_small_video_is_sent_in_one_chunk_then_tracked_until_publish_complete(): void
    {
        Queue::fake();
        $this->fakeTikTok(status: Http::response(['data' => ['status' => 'PUBLISH_COMPLETE'], 'error' => ['code' => 'ok']]));
        $post = $this->createPost();
        $this->publishAndRun($post);

        // Init : FILE_UPLOAD, taille exacte, un seul morceau, réglages explicites.
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/video/init/')) {
                return false;
            }
            $d = $r->data();

            return $r->hasHeader('Authorization', 'Bearer '.self::TT_ACCESS)
                && $d['source_info'] === ['source' => 'FILE_UPLOAD', 'video_size' => 2048, 'chunk_size' => 2048, 'total_chunk_count' => 1]
                && $d['post_info']['privacy_level'] === 'SELF_ONLY'
                && $d['post_info']['disable_comment'] === true && $d['post_info']['disable_duet'] === true && $d['post_info']['disable_stitch'] === true
                && $d['post_info']['brand_content_toggle'] === false && $d['post_info']['brand_organic_toggle'] === false
                && $d['post_info']['title'] === 'Ma vidéo TikTok #tuto #laravel';
        });
        $this->assertSame([['range' => 'bytes 0-2047/2048', 'length' => '2048', 'size' => 2048]], $this->chunks);

        // Processing : variante en publication, publish_id suivi, aucun nouvel envoi.
        $attempt = PublicationAttempt::sole();
        $this->assertSame('in_progress', $attempt->status->value);
        $this->assertSame('v_pub_file~v2.123', $attempt->external_post_id);
        $this->assertSame(VariantStatus::Publishing, $this->variant($post)->fresh()->status);
        Queue::assertPushed(ReconcilePublicationJob::class, fn ($job) => $job->trackingId === 'v_pub_file~v2.123');

        // Suivi : PUBLISH_COMPLETE sans identifiant public (vidéo privée) → publish_id conservé.
        $this->runLastReconcile();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/status/fetch/') && $r['publish_id'] === 'v_pub_file~v2.123');
        $this->assertSame('succeeded', PublicationAttempt::sole()->status->value);
        $this->assertSame('v_pub_file~v2.123', PublicationAttempt::sole()->external_post_id);
        $this->assertSame(VariantStatus::Published, $this->variant($post)->fresh()->status);
        $this->assertSame('published', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
    }

    public function test_public_post_id_replaces_the_publish_id_when_available(): void
    {
        Queue::fake();
        $this->fakeTikTok(status: Http::response(['data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => [7301234567890123456]], 'error' => ['code' => 'ok']]));
        $post = $this->createPost(['privacy' => 'public']);
        $this->publishAndRun($post);
        $this->runLastReconcile();

        $this->assertSame('7301234567890123456', PublicationAttempt::sole()->external_post_id);
        $this->assertSame('v_pub_file~v2.123', PublicationAttempt::sole()->response_excerpt['publish_id']);
        $this->assertSame('7301234567890123456', $this->variant($post)->fresh()->external_post_id);
    }

    public function test_multi_chunk_upload_uses_correct_ranges_and_sequence(): void
    {
        Queue::fake();
        config(['services.tiktok.chunk_size_mb' => 5]);
        $this->video = $this->tiktokVideo($this->user, 12 * 1024 * 1024);
        $this->fakeTikTok();
        $post = $this->createPost();
        $this->publishAndRun($post);

        $mb = 1024 * 1024;
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/video/init/')
            && $r['source_info']['chunk_size'] === 5 * $mb && $r['source_info']['total_chunk_count'] === 2 && $r['source_info']['video_size'] === 12 * $mb);
        $this->assertSame([
            ['range' => 'bytes 0-'.(5 * $mb - 1).'/'.(12 * $mb), 'length' => (string) (5 * $mb), 'size' => 5 * $mb],
            ['range' => 'bytes '.(5 * $mb).'-'.(12 * $mb - 1).'/'.(12 * $mb), 'length' => (string) (7 * $mb), 'size' => 7 * $mb],
        ], $this->chunks);
        $this->assertSame('in_progress', PublicationAttempt::sole()->status->value);
    }

    public function test_status_failed_fails_without_retry_and_manual_retry_creates_a_new_attempt(): void
    {
        Queue::fake();
        $this->fakeTikTok(status: Http::response(['data' => ['status' => 'FAILED', 'fail_reason' => 'video_pull_failed'], 'error' => ['code' => 'ok']]));
        $post = $this->createPost();
        $this->publishAndRun($post);
        $this->runLastReconcile();

        $this->assertSame('failed', PublicationAttempt::sole()->status->value);
        $this->assertSame('PUBLISH_FAILED', $this->variant($post)->fresh()->last_error_code);
        $this->assertStringContainsString('video_pull_failed', $this->variant($post)->fresh()->last_error_message);
        Queue::assertPushed(PublishPostVariantJob::class, 1);

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertOk();
        Queue::pushed(PublishPostVariantJob::class)->last()->handle(app(PublishingService::class));
        $this->assertSame(2, PublicationAttempt::count());
    }

    public function test_unexpected_status_is_unknown_and_retry_requires_confirmation(): void
    {
        Queue::fake();
        $this->fakeTikTok(status: Http::response(['data' => ['status' => 'SEND_TO_USER_INBOX'], 'error' => ['code' => 'ok']]));
        $post = $this->createPost();
        $this->publishAndRun($post);
        $this->runLastReconcile();

        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry", ['confirm_unknown' => true])->assertOk();
    }

    // --- Erreurs avant transmission complète : relance sûre -----------------------------------

    public function test_init_rate_limit_is_temporary_and_retried_by_the_engine(): void
    {
        Queue::fake();
        $this->fakeTikTok(init: Http::response(['data' => [], 'error' => ['code' => 'rate_limit_exceeded', 'message' => '', 'log_id' => 'x']], 429));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('RATE_LIMITED', PublicationAttempt::sole()->error_code);
        $this->assertTrue(PublicationAttempt::sole()->retryable);
        $this->assertSame(VariantStatus::Queued, $this->variant($post)->fresh()->status);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'auto_retry');
        $this->assertSame([], $this->chunks);
    }

    public function test_init_permanent_error_fails_without_retry(): void
    {
        Queue::fake();
        $this->fakeTikTok(init: Http::response(['data' => [], 'error' => ['code' => 'spam_risk_too_many_posts', 'message' => '', 'log_id' => 'x']]));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('DAILY_POST_LIMIT', PublicationAttempt::sole()->error_code);
        $this->assertSame('failed', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
    }

    public function test_interrupted_intermediate_chunk_is_temporary(): void
    {
        Queue::fake();
        config(['services.tiktok.chunk_size_mb' => 5]);
        $this->video = $this->tiktokVideo($this->user, 11 * 1024 * 1024);
        $this->fakeTikTok(upload: fn (int $i) => throw new ConnectionException('coupure'));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('UPLOAD_INTERRUPTED', PublicationAttempt::sole()->error_code);
        $this->assertSame(VariantStatus::Queued, $this->variant($post)->fresh()->status);
        Queue::assertNotPushed(ReconcilePublicationJob::class);
    }

    public function test_lost_response_on_the_last_chunk_is_tracked_never_resent(): void
    {
        Queue::fake();
        $this->fakeTikTok(upload: fn (int $i) => throw new ConnectionException('réponse perdue'));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $attempt = PublicationAttempt::sole();
        $this->assertSame('in_progress', $attempt->status->value);
        $this->assertSame('v_pub_file~v2.123', $attempt->external_post_id);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        Queue::assertPushed(ReconcilePublicationJob::class, 1);
    }

    public function test_expired_access_token_on_init_is_refreshed_once(): void
    {
        Queue::fake();
        $calls = 0;
        $this->fakeTikTok(init: function () use (&$calls) {
            return ++$calls === 1
                ? Http::response(['data' => [], 'error' => ['code' => 'access_token_invalid', 'message' => '', 'log_id' => 'x']], 401)
                : Http::response(['data' => ['publish_id' => 'v_pub_file~v2.123', 'upload_url' => self::TT_UPLOAD_URL], 'error' => ['code' => 'ok']]);
        });
        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['access_token' => 'act.frais', 'expires_in' => 86400, 'open_id' => self::TT_OPEN_ID, 'refresh_token' => 'rft.frais', 'refresh_expires_in' => 31536000, 'scope' => 'user.info.basic,video.publish'])]);
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('in_progress', PublicationAttempt::sole()->status->value);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/video/init/') && $r->hasHeader('Authorization', 'Bearer act.frais'));
    }

    // --- Refus avant tout envoi -----------------------------------------------------------------

    public function test_privacy_not_offered_by_creator_info_stops_before_init(): void
    {
        Queue::fake();
        $this->fakeTikTok(['privacy_level_options' => ['SELF_ONLY']]);
        $post = $this->createPost(['privacy' => 'public']);
        $this->publishAndRun($post);

        $this->assertSame('PRIVACY_NOT_ALLOWED', PublicationAttempt::sole()->error_code);
        $this->assertFalse($this->initSent());
    }

    public function test_disabled_interaction_stops_before_init(): void
    {
        Queue::fake();
        $this->fakeTikTok(['duet_disabled' => true]);
        $post = $this->createPost(['allowDuet' => true]);
        $this->publishAndRun($post);

        $this->assertSame('INTERACTION_NOT_ALLOWED', PublicationAttempt::sole()->error_code);
        $this->assertFalse($this->initSent());
    }

    public function test_video_longer_than_allowed_stops_before_init(): void
    {
        Queue::fake();
        $this->video = $this->tiktokVideo($this->user, 2048, 400_000);
        $this->fakeTikTok(['max_video_post_duration_sec' => 300]);
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('VIDEO_TOO_LONG', PublicationAttempt::sole()->error_code);
        $this->assertFalse($this->initSent());
    }

    public function test_missing_privacy_choice_is_refused_without_any_call(): void
    {
        Queue::fake();
        $post = $this->createPost(['privacy' => null]);
        $this->publishAndRun($post);

        $this->assertSame('PRIVACY_REQUIRED', PublicationAttempt::sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_commercial_content_requires_a_brand_choice_and_is_sent_explicitly(): void
    {
        Queue::fake();
        $post = $this->createPost(['commercialContent' => true]);
        $this->publishAndRun($post);
        $this->assertSame('COMMERCIAL_CONTENT_INCOMPLETE', PublicationAttempt::sole()->error_code);

        $this->fakeTikTok();
        $other = $this->createPost(['commercialContent' => true, 'brandContent' => true, 'privacy' => 'public'] + [], null);
        app(PublishingService::class)->publishNow($this->user, $other);
        Queue::pushed(PublishPostVariantJob::class)->last()->handle(app(PublishingService::class));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/video/init/') && $r['post_info']['brand_content_toggle'] === true && $r['post_info']['brand_organic_toggle'] === false);
    }

    public function test_missing_or_too_large_file_is_refused_without_any_call(): void
    {
        Queue::fake();
        $post = $this->createPost();
        Storage::disk('media')->delete($this->video->path);
        $this->publishAndRun($post);
        $this->assertSame('MEDIA_NOT_FOUND', PublicationAttempt::sole()->error_code);

        config(['services.tiktok.max_upload_mb' => 1]);
        $big = $this->tiktokVideo($this->user, (int) (1.5 * 1024 * 1024));
        $other = $this->createPost([], [$big->id]);
        app(PublishingService::class)->publishNow($this->user, $other);
        Queue::pushed(PublishPostVariantJob::class)->last()->handle(app(PublishingService::class));
        $this->assertSame('MEDIA_TOO_LARGE', $other->variants()->first()->last_error_code);
        Http::assertNothingSent();
    }

    public function test_missing_credentials_fall_back_to_not_configured(): void
    {
        Queue::fake();
        $post = $this->createPost();
        config(['services.tiktok.client_key' => null]);
        $this->publishAndRun($post);

        $this->assertSame('NOT_CONFIGURED', PublicationAttempt::sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_tokens_never_appear_in_attempts_or_api_responses(): void
    {
        Queue::fake();
        $this->fakeTikTok();
        $post = $this->createPost();
        $this->publishAndRun($post);

        $json = $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}/attempts")->getContent()
            .json_encode(PublicationAttempt::sole()->toArray())
            .json_encode(Queue::pushed(ReconcilePublicationJob::class)->first());
        foreach ([self::TT_ACCESS, self::TT_REFRESH, 'test-tiktok-client-secret', 'upload_token=TOK'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }
}
