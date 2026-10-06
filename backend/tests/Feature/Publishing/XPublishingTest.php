<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\Adapters\XPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\PublisherRegistry;
use App\Domain\Publishing\PublishingService;
use App\Domain\Publishing\Support\XPostLength;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Jobs\PublishPostVariantJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Social\Concerns\XFixtures;
use Tests\TestCase;

/**
 * X dans le moteur de publication : PublisherRegistry → XPublisher → XTokenManager → (faux)
 * POST /2/tweets → PublishResult → PublishingService. Aucun appel réel à X.
 */
class XPublishingTest extends TestCase
{
    use RefreshDatabase, XFixtures;

    private const POST_ID = '1846987139428634858';

    private const CREATE_URL = 'https://api.x.com/2/tweets';

    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    private User $user;

    private SocialAccount $account;

    /** @var list<array{headers: array<string, mixed>, body: array<string, mixed>}> */
    private array $sent = [];

    private ?Closure $responder = null;

    private ?Closure $tokenResponder = null;

    /** @var list<array<string, mixed>> */
    private array $logs = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logs[] = [$e->message, $e->context];
        });
        $this->user = User::factory()->create();
        $this->account = $this->xAccount($this->user);
        $this->respond(fn () => Http::response(['data' => ['id' => self::POST_ID, 'text' => 'ok', 'edit_history_tweet_ids' => [self::POST_ID]]], 201));
        $this->tokenResponder = fn () => Http::response(['error' => 'invalid_request'], 400);
        Http::fake(function (Request $request) {
            if ($request->url() === self::TOKEN_URL) {
                return ($this->tokenResponder)($request);
            }
            if ($request->url() !== self::CREATE_URL) {
                return null;
            }
            $this->sent[] = ['headers' => $request->headers(), 'body' => $request->data()];

            return ($this->responder)($request);
        });
    }

    /** Réponse du faux serveur X (un seul Http::fake : les faux ne s'empilent pas). */
    private function respond(Closure $responder): void
    {
        $this->responder = $responder;
    }

    private function savePost(string $body, array $options = [], array $mediaIds = [], ?SocialAccount $account = null, array $extra = [])
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'body' => $body,
            'media_ids' => $mediaIds,
            'targets' => [['social_account_id' => ($account ?? $this->account)->id, 'format' => 'feed', 'options' => $options]],
        ], $extra));
    }

    private function createPost(string $body = 'Bonjour depuis SoxialConnect.', array $options = [], array $mediaIds = [], ?SocialAccount $account = null): Post
    {
        return Post::findOrFail($this->savePost($body, $options, $mediaIds, $account)->assertCreated()->json('data.id'));
    }

    private function publishAndRun(Post $post): PostVariant
    {
        app(PublishingService::class)->publishNow($this->user, $post);
        Queue::pushed(PublishPostVariantJob::class)->values()[0]->handle(app(PublishingService::class));

        return $post->variants()->firstOrFail()->fresh();
    }

    private function request(string $body, array $media = []): PublishRequest
    {
        return new PublishRequest('p', 'v', $this->account->id, 'x', self::X_USER_ID, 'feed', $body, [], $media, 'k', 1);
    }

    // --- Succès -----------------------------------------------------------------------------

    public function test_publish_now_sends_the_text_and_records_the_real_post_id(): void
    {
        $id = $this->savePost('Bonjour depuis SoxialConnect.', ['hashtags' => ['soxial']])->assertCreated()->json('data.id');
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$id}/publish")->assertOk();
        Queue::pushed(PublishPostVariantJob::class)->values()[0]->handle(app(PublishingService::class));

        $variant = Post::findOrFail($id)->variants()->sole();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(self::POST_ID, $variant->external_post_id);
        $this->assertSame('https://x.com/i/web/status/'.self::POST_ID, $variant->external_url);
        $this->assertSame('published', Post::findOrFail($id)->status->value);
        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame(self::POST_ID, $attempt->external_post_id);
        $this->assertSame($this->account->id, $attempt->social_account_id);
        $this->assertSame(1, $attempt->attempt_number);

        // Requête conforme à « Create Post » : texte seul, jeton fourni par XTokenManager.
        $this->assertCount(1, $this->sent);
        $this->assertSame(['Bearer '.self::X_ACCESS], $this->sent[0]['headers']['Authorization']);
        $this->assertSame(['text' => "Bonjour depuis SoxialConnect.\n\n#soxial"], $this->sent[0]['body']);

        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$id}/attempts")->assertOk()
            ->assertJsonPath('data.0.status', 'succeeded');
    }

    public function test_the_registry_resolves_x_to_the_x_publisher(): void
    {
        $this->assertInstanceOf(XPublisher::class, app(PublisherRegistry::class)->for('x'));
    }

    public function test_the_selected_x_account_and_its_token_are_used(): void
    {
        $other = $this->xAccount($this->user, ['access_token_encrypted' => 'x-autre-token'], ['external_account_id' => '42', 'username' => '@autre']);
        $this->publishAndRun($this->createPost(account: $other));

        $this->assertSame(['Bearer x-autre-token'], $this->sent[0]['headers']['Authorization']);
        $this->assertSame($other->id, PublicationAttempt::sole()->social_account_id);
    }

    public function test_scheduled_post_goes_through_the_existing_scheduler(): void
    {
        $this->savePost('Programmé sur X', extra: ['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(10)->toIso8601String()])->assertCreated();
        $this->assertSame(0, app(PublishingService::class)->dispatchDue());

        $this->travel(11)->minutes();
        $this->assertSame(1, app(PublishingService::class)->dispatchDue());
        Queue::pushed(PublishPostVariantJob::class)->values()[0]->handle(app(PublishingService::class));

        $this->assertSame(['text' => 'Programmé sur X'], $this->sent[0]['body']);
        $this->assertSame(VariantStatus::Published, PostVariant::sole()->status);
    }

    // --- Refus avant envoi ----------------------------------------------------------------------

    public function test_media_are_refused_without_any_call(): void
    {
        Storage::fake('media');
        $image = Media::factory()->for($this->user)->create();
        Storage::disk('media')->put($image->path, 'img');
        $variant = $this->publishAndRun($this->createPost('Avec image', mediaIds: [$image->id]));

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('MEDIA_NOT_SUPPORTED', $variant->last_error_code);
        $this->assertSame([], $this->sent);
    }

    public function test_empty_text_is_refused_without_any_call(): void
    {
        $result = app(XPublisher::class)->publish($this->request("  \n "));

        $this->assertSame('EMPTY_CONTENT', $result->code);
        $this->assertFalse($result->retryable);
        $this->assertSame([], $this->sent);
    }

    public function test_text_over_the_x_limit_is_refused_without_any_call_and_never_truncated(): void
    {
        $variant = $this->publishAndRun($this->createPost(str_repeat('a', 281)));
        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('TEXT_TOO_LONG', $variant->last_error_code);
        $this->assertStringContainsString('281', $variant->last_error_message);

        // Décompte de X : un emoji compte 2 → 141 emoji = 282.
        $this->assertSame('TEXT_TOO_LONG', app(XPublisher::class)->publish($this->request(str_repeat('👍', 141)))->code);
        $this->assertSame([], $this->sent);
    }

    public function test_text_at_the_x_limit_is_sent_unchanged(): void
    {
        $text = str_repeat('é', 250).' https://exemple.test/'.str_repeat('x', 100); // 250 + 1 + 23 = 274
        $this->assertSame(274, XPostLength::of($text));
        $this->assertSame(280, XPostLength::of(str_repeat('👍', 140)));
        $this->assertSame(4, XPostLength::of('日本'));

        $this->publishAndRun($this->createPost($text));
        $this->assertSame(['text' => $text], $this->sent[0]['body']);
    }

    public function test_not_configured_server_sends_nothing(): void
    {
        config(['services.x.client_secret' => null]);
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame('NOT_CONFIGURED', $variant->last_error_code);
        $this->assertSame([], $this->sent);
    }

    // --- Jeton (XTokenManager) ------------------------------------------------------------------

    public function test_expired_token_is_refreshed_by_the_token_manager_then_the_post_is_sent(): void
    {
        $this->account->tokens()->update(['expires_at' => now()->subMinute()]);
        $this->tokenResponder = fn () => Http::response([
            'token_type' => 'bearer', 'expires_in' => 7200, 'access_token' => 'x-access-renouvele',
            'scope' => 'tweet.write users.read tweet.read offline.access', 'refresh_token' => 'x-refresh-renouvele',
        ]);
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(['Bearer x-access-renouvele'], $this->sent[0]['headers']['Authorization']);
        $token = SocialToken::query()->where('social_account_id', $this->account->id)->sole();
        $this->assertSame('x-refresh-renouvele', $token->refresh_token_encrypted, 'Rotation : nouveau refresh token enregistré.');
    }

    public function test_refused_refresh_fails_cleanly_and_asks_for_reconnection(): void
    {
        $this->account->tokens()->update(['expires_at' => now()->subMinute()]);
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('TOKEN_REVOKED', $variant->last_error_code);
        $this->assertFalse(PublicationAttempt::sole()->retryable);
        $this->assertSame(SocialAccountStatus::Expired, $this->account->fresh()->status);
        $this->assertSame(1, $this->account->tokens()->count(), 'Les tokens ne sont pas effacés.');
        $this->assertSame([], $this->sent);
    }

    // --- Erreurs X ------------------------------------------------------------------------------

    public function test_rejected_token_flags_the_account_for_reconnection(): void
    {
        $this->respond(fn () => Http::response(['title' => 'Unauthorized', 'type' => 'about:blank', 'status' => 401, 'detail' => 'Unauthorized'], 401));
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame('TOKEN_INVALID', $variant->last_error_code);
        $this->assertFalse(PublicationAttempt::sole()->retryable);
        $this->assertSame(SocialAccountStatus::Expired, $this->account->fresh()->status);
        $this->assertSame('invalid', $this->account->tokens()->sole()->status->value);
    }

    public function test_api_errors_are_classified_as_permanent(): void
    {
        foreach ([
            [400, ['title' => 'Invalid Request', 'type' => 'https://api.x.com/2/problems/invalid-request', 'detail' => 'One or more parameters to your request was invalid.'], 'INVALID_REQUEST'],
            [403, ['title' => 'Forbidden', 'type' => 'about:blank', 'detail' => 'You are not allowed to create a Tweet with duplicate content.'], 'DUPLICATE_CONTENT'],
            [403, ['title' => 'Client Forbidden', 'type' => 'https://api.x.com/2/problems/client-forbidden', 'reason' => 'client-not-enrolled', 'client_id' => 'id-client-ne-doit-pas-etre-garde', 'detail' => 'When authenticating requests…'], 'API_ACCESS_DENIED'],
            [402, ['title' => 'Payment Required', 'type' => 'about:blank', 'detail' => 'Your account does not have enough credits.'], 'API_ACCESS_DENIED'],
            [403, ['title' => 'Forbidden', 'type' => 'about:blank', 'detail' => 'You are not permitted to perform this action.'], 'PERMISSION_DENIED'],
            [409, ['title' => 'Conflict', 'type' => 'about:blank', 'detail' => 'Conflict'], 'HTTP_ERROR'],
        ] as $i => [$status, $body, $code]) {
            Queue::fake();
            $this->respond(fn () => Http::response($body + ['status' => $status], $status));
            $variant = $this->publishAndRun($this->createPost("Erreur {$i}"));

            $this->assertSame(VariantStatus::Failed, $variant->status, $code);
            $this->assertSame($code, $variant->last_error_code, (string) $i);
            $attempt = PublicationAttempt::query()->where('post_variant_id', $variant->id)->sole();
            $this->assertSame($status, $attempt->http_status);
            $this->assertFalse($attempt->retryable);
            Queue::assertPushed(PublishPostVariantJob::class, 1); // aucune relance
        }
        $this->assertStringNotContainsString('id-client-ne-doit-pas-etre-garde', (string) json_encode(DB::table('publication_attempts')->get()));
    }

    public function test_rate_limit_is_temporary_and_follows_the_engine_backoff(): void
    {
        $reset = now()->addMinutes(15)->getTimestamp();
        $this->respond(fn () => Http::response(['title' => 'Too Many Requests', 'type' => 'about:blank', 'status' => 429, 'detail' => 'Too Many Requests'], 429, ['x-rate-limit-reset' => (string) $reset]));
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame(VariantStatus::Queued, $variant->status);
        $attempt = PublicationAttempt::sole();
        $this->assertSame('RATE_LIMITED', $attempt->error_code);
        $this->assertTrue($attempt->retryable);
        $this->assertSame(429, $attempt->http_status);
        $this->assertSame(now()->setTimestamp($reset)->toIso8601String(), $attempt->response_excerpt['rate_limit_reset_at']);
        // Nouvelle tentative différée selon publishing.backoff (60 s), jamais immédiate.
        Queue::assertPushed(PublishPostVariantJob::class, fn (PublishPostVariantJob $job) => $job->cycleAttempt === 2 && $job->delay !== null);

        // Toujours limité : après le nombre maximal de tentatives automatiques, échec sans boucle.
        foreach ([2, 3] as $cycle) {
            Queue::pushed(PublishPostVariantJob::class, fn (PublishPostVariantJob $job) => $job->cycleAttempt === $cycle)->first()->handle(app(PublishingService::class));
        }
        $this->assertSame(VariantStatus::Failed, $variant->fresh()->status);
        $this->assertSame(3, PublicationAttempt::count());
        Queue::assertPushed(PublishPostVariantJob::class, 3);
    }

    public function test_server_error_timeout_or_invalid_response_after_sending_is_unknown_and_never_retried(): void
    {
        foreach ([
            fn () => Http::response(['title' => 'Internal Server Error', 'status' => 500], 500),
            fn () => Http::response(['title' => 'Service Unavailable', 'status' => 503], 503),
            fn (Request $r) => Http::failedConnection('cURL error 28: Operation timed out')($r),
            fn () => Http::response('<html>pas du JSON</html>', 201),
            fn () => Http::response(['data' => ['text' => 'sans id']], 201),
        ] as $i => $failure) {
            Queue::fake();
            $this->respond($failure);
            $variant = $this->publishAndRun($this->createPost("Inconnu {$i}"));

            $this->assertSame(VariantStatus::Failed, $variant->status, (string) $i);
            $this->assertSame(PublishingService::UNKNOWN_CODE, $variant->last_error_code, (string) $i);
            $this->assertNull($variant->external_post_id, 'Aucun identifiant inventé.');
            Queue::assertPushed(PublishPostVariantJob::class, 1);
        }
    }

    public function test_connection_failure_before_sending_is_temporary(): void
    {
        $this->respond(fn (Request $r) => Http::failedConnection('cURL error 7: Failed to connect')($r));
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertSame('NETWORK_ERROR', PublicationAttempt::sole()->error_code);
    }

    // --- Sécurité -----------------------------------------------------------------------------

    public function test_tokens_and_secret_never_leave_the_encrypted_columns(): void
    {
        $this->account->tokens()->update(['expires_at' => now()->subMinute()]);
        $this->tokenResponder = fn () => Http::response(['access_token' => 'x-access-renouvele', 'expires_in' => 7200, 'refresh_token' => 'x-refresh-renouvele', 'scope' => 'tweet.write']);
        $this->respond(fn () => Http::response(['title' => 'Unauthorized', 'status' => 401], 401));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $secrets = [self::X_ACCESS, self::X_REFRESH, 'x-access-renouvele', 'x-refresh-renouvele', self::X_CLIENT_SECRET];
        foreach (['publication_attempts', 'post_variants', 'posts', 'audit_logs', 'social_accounts'] as $table) {
            $dump = (string) json_encode(DB::table($table)->get());
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString($secret, $dump, $table);
            }
        }
        $api = $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}/attempts")->getContent()
            .$this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}")->getContent()
            .$this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->getContent();
        $logs = (string) json_encode($this->logs);
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $api);
            $this->assertStringNotContainsString($secret, $logs);
        }
    }

    public function test_another_users_x_account_cannot_be_targeted(): void
    {
        $foreign = $this->xAccount(User::factory()->create(), ['access_token_encrypted' => 'x-token-de-b'], ['external_account_id' => '777']);

        foreach (['draft', 'schedule'] as $mode) {
            $this->savePost('Intrusion', account: $foreign, extra: ['mode' => $mode, 'scheduled_at' => now()->addHour()->toIso8601String()])
                ->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);
        }
        $this->assertSame(0, Post::count());
        $this->assertSame([], $this->sent);
    }
}
