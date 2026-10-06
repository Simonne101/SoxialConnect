<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\Adapters\LinkedInPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\PublishingService;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Jobs\PublishPostVariantJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Social\Concerns\LinkedInFixtures;
use Tests\TestCase;

/**
 * LinkedIn dans le moteur de publication : PublisherRegistry → LinkedInPublisher → (faux)
 * POST /v2/ugcPosts → PublishResult → PublishingService. Aucun appel réel à LinkedIn.
 */
class LinkedInPublishingTest extends TestCase
{
    use LinkedInFixtures, RefreshDatabase;

    private const POST_URN = 'urn:li:share:7200000000000000001';

    private User $user;

    private SocialAccount $account;

    /** @var list<array{headers: array<string, mixed>, body: array<string, mixed>}> */
    private array $sent = [];

    private ?Closure $responder = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->account = $this->linkedinAccount($this->user);
        $this->respond(fn () => Http::response('', 201, ['X-RestLi-Id' => self::POST_URN]));
        Http::fake(function (Request $request) {
            if ($request->url() !== 'https://api.linkedin.com/v2/ugcPosts') {
                return null;
            }
            $this->sent[] = ['headers' => $request->headers(), 'body' => $request->data()];

            return ($this->responder)($request);
        });
    }

    /** Réponse du faux serveur LinkedIn (un seul Http::fake : les faux ne s'empilent pas). */
    private function respond(Closure $responder): void
    {
        $this->responder = $responder;
    }

    private function createPost(string $body = 'Bonjour LinkedIn', array $options = [], array $mediaIds = [], ?SocialAccount $account = null): Post
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => $body,
            'media_ids' => $mediaIds,
            'targets' => [['social_account_id' => ($account ?? $this->account)->id, 'format' => 'feed', 'options' => $options]],
        ])->assertCreated()->json('data.id');

        return Post::findOrFail($id);
    }

    private function publishAndRun(Post $post): PostVariant
    {
        app(PublishingService::class)->publishNow($this->user, $post);
        Queue::pushed(PublishPostVariantJob::class)->values()[0]->handle(app(PublishingService::class));

        return $post->variants()->firstOrFail()->fresh();
    }

    // --- Succès -----------------------------------------------------------------------------

    public function test_text_post_is_published_with_the_real_post_urn(): void
    {
        $variant = $this->publishAndRun($this->createPost('Bonjour LinkedIn', ['hashtags' => ['soxial', 'test']]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(self::POST_URN, $variant->external_post_id);
        $this->assertSame('https://www.linkedin.com/feed/update/'.self::POST_URN.'/', $variant->external_url);
        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame(self::POST_URN, $attempt->external_post_id);
        $this->assertSame($this->account->id, $attempt->social_account_id);

        // Requête conforme à « Share on LinkedIn » (ugcPosts).
        $sent = $this->sent[0];
        $this->assertSame(['Bearer '.self::LI_ACCESS], $sent['headers']['Authorization']);
        $this->assertSame(['2.0.0'], $sent['headers']['X-Restli-Protocol-Version']);
        $this->assertSame([
            'author' => 'urn:li:person:'.self::LI_SUB,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => ['com.linkedin.ugc.ShareContent' => [
                'shareCommentary' => ['text' => "Bonjour LinkedIn\n\n#soxial #test"],
                'shareMediaCategory' => 'NONE',
            ]],
            'visibility' => ['com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC'],
        ], $sent['body']);
    }

    public function test_the_selected_linkedin_account_is_used(): void
    {
        $other = $this->linkedinAccount($this->user, ['access_token_encrypted' => 'AQX-autre-token'], [
            'external_account_id' => 'li-autre-membre', 'metadata' => ['provider' => 'linkedin', 'person_urn' => 'urn:li:person:li-autre-membre'],
        ]);
        $this->publishAndRun($this->createPost(account: $other));

        $this->assertSame(['Bearer AQX-autre-token'], $this->sent[0]['headers']['Authorization']);
        $this->assertSame('urn:li:person:li-autre-membre', $this->sent[0]['body']['author']);
    }

    public function test_hashtags_already_in_the_text_are_not_repeated(): void
    {
        $request = new PublishRequest('p', 'v', 'a', 'linkedin', self::LI_SUB, 'feed', 'Lancement #Soxial', ['hashtags' => ['soxial', 'nouveau']], [], 'k', 1);
        $this->assertSame("Lancement #Soxial\n\n#nouveau", app(LinkedInPublisher::class)->text($request));
    }

    // --- Refus avant envoi ----------------------------------------------------------------------

    public function test_media_are_refused_without_any_call_in_v1(): void
    {
        Storage::fake('media');
        $image = Media::factory()->for($this->user)->create();
        Storage::disk('media')->put($image->path, 'img');
        $variant = $this->publishAndRun($this->createPost('Avec image', mediaIds: [$image->id]));

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('MEDIA_NOT_SUPPORTED', $variant->last_error_code);
        $this->assertSame([], $this->sent);
    }

    public function test_not_configured_server_sends_nothing(): void
    {
        config(['services.linkedin.client_secret' => null]);
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame('NOT_CONFIGURED', $variant->last_error_code);
        $this->assertSame([], $this->sent);
    }

    public function test_expired_token_requires_reconnection_without_any_call(): void
    {
        $this->account->tokens()->update(['expires_at' => now()->subMinute()]);
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('TOKEN_EXPIRED', $variant->last_error_code);
        $this->assertSame(SocialAccountStatus::Expired, $this->account->fresh()->status);
        $this->assertSame([], $this->sent);
    }

    // --- Erreurs LinkedIn -----------------------------------------------------------------------

    public function test_rejected_token_flags_the_account_for_reconnection(): void
    {
        $this->respond(fn () => Http::response(['status' => 401, 'serviceErrorCode' => 65600, 'message' => 'Invalid access token'], 401));
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame('TOKEN_INVALID', $variant->last_error_code);
        $this->assertFalse(PublicationAttempt::sole()->retryable);
        $this->assertSame(SocialAccountStatus::Expired, $this->account->fresh()->status);
        $this->assertSame('invalid', $this->account->tokens()->sole()->status->value);
    }

    public function test_api_errors_are_classified(): void
    {
        foreach ([
            [403, ['message' => 'Not enough permissions to access: ugcPosts.CREATE'], 'PERMISSION_DENIED', VariantStatus::Failed],
            [422, ['message' => 'Content is a duplicate of urn:li:share:123'], 'DUPLICATE_CONTENT', VariantStatus::Failed],
            [400, ['message' => 'Invalid commentary'], 'INVALID_REQUEST', VariantStatus::Failed],
            [429, ['message' => 'Too many requests'], 'RATE_LIMITED', VariantStatus::Queued],
        ] as [$status, $body, $code, $expected]) {
            Queue::fake();
            $this->respond(fn () => Http::response($body + ['status' => $status], $status));
            $variant = $this->publishAndRun($this->createPost("Erreur {$status}"));

            $this->assertSame($expected, $variant->status, (string) $status);
            $this->assertSame($code, $variant->last_error_code, (string) $status);
            $this->assertSame($status, PublicationAttempt::query()->where('post_variant_id', $variant->id)->sole()->http_status);
        }
    }

    public function test_server_error_or_timeout_after_sending_is_unknown_and_never_retried(): void
    {
        foreach ([
            fn () => Http::response(['message' => 'Internal error'], 500),
            fn (Request $r) => Http::failedConnection('cURL error 28: Operation timed out')($r),
        ] as $failure) {
            Queue::fake();
            $this->respond($failure);
            $variant = $this->publishAndRun($this->createPost('Inconnu '.count($this->sent)));

            $this->assertSame(VariantStatus::Failed, $variant->status);
            $this->assertSame(PublishingService::UNKNOWN_CODE, $variant->last_error_code);
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

    public function test_the_access_token_is_never_stored_outside_the_encrypted_column(): void
    {
        $this->respond(fn () => Http::response(['status' => 401, 'message' => 'Invalid access token'], 401));
        $this->publishAndRun($this->createPost());

        foreach (['publication_attempts', 'post_variants', 'audit_logs', 'social_accounts'] as $table) {
            $this->assertStringNotContainsString(self::LI_ACCESS, (string) json_encode(DB::table($table)->get()), $table);
        }
    }
}
