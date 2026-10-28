<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\PublishingService;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Jobs\PublishPostVariantJob;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Social\Concerns\FacebookFixtures;
use Tests\TestCase;

/**
 * Facebook dans le moteur de publication : PublisherRegistry → FacebookPublisher → (faux) API Graph
 * (feed, photos, videos) → PublishResult → PublishingService. Aucun appel réel à Facebook.
 */
class FacebookPublishingTest extends TestCase
{
    use FacebookFixtures, RefreshDatabase;

    private const POST_ID = '102030405060708_900000000000001';

    private User $user;

    private SocialAccount $account;

    /** @var list<array{path: string, headers: array<string, mixed>, body: array<string, mixed>}> */
    private array $sent = [];

    private ?Closure $responder = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('media');
        $this->user = User::factory()->create();
        $this->account = $this->facebookPage($this->user);
        $photo = 0;
        $this->respond(function (string $path) use (&$photo) {
            return match (true) {
                str_ends_with($path, '/feed') => Http::response(['id' => self::POST_ID]),
                str_ends_with($path, '/photos') => Http::response(['id' => '7700'.(++$photo), 'post_id' => self::FB_PAGE_ID.'_7700'.$photo]),
                str_ends_with($path, '/videos') => Http::response(['id' => '880000000000001']),
                default => Http::response(['error' => ['message' => 'Unknown path', 'code' => 100]], 400),
            };
        });
        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), self::FB_GRAPH)) {
                return null;
            }
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/v23.0/'));
            $this->sent[] = ['path' => $path, 'headers' => $request->headers(), 'body' => $request->data()];

            return ($this->responder)($path, $request);
        });
    }

    /** Réponse du faux API Graph (un seul Http::fake : les faux ne s'empilent pas). */
    private function respond(Closure $responder): void
    {
        $this->responder = $responder;
    }

    private function createPost(string $body = 'Bonjour Facebook', array $options = [], array $mediaIds = [], string $format = 'feed'): Post
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => $body,
            'media_ids' => $mediaIds,
            'targets' => [['social_account_id' => $this->account->id, 'format' => $format, 'options' => $options]],
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

    public function test_text_post_is_published_on_the_page_with_its_page_token(): void
    {
        $variant = $this->publishAndRun($this->createPost('Bonjour Facebook', ['hashtags' => ['pain', 'local']]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(self::POST_ID, $variant->external_post_id);
        $this->assertSame('https://www.facebook.com/'.self::POST_ID, $variant->external_url);
        $this->assertSame('succeeded', PublicationAttempt::sole()->status->value);

        $sent = $this->sent[0];
        $this->assertSame(self::FB_PAGE_ID.'/feed', $sent['path']);
        $this->assertSame(['Bearer '.self::FB_PAGE_TOKEN], $sent['headers']['Authorization']);
        $this->assertSame("Bonjour Facebook\n\n#pain #local", $sent['body']['message']);
        $this->assertSame($this->fbProof(self::FB_PAGE_TOKEN), $sent['body']['appsecret_proof']);
        $this->assertArrayNotHasKey('access_token', $sent['body'], 'Jeton uniquement dans l\'en-tête.');
    }

    public function test_a_single_photo_is_sent_by_signed_url_with_its_caption(): void
    {
        $image = $this->facebookMedia($this->user, 'image/jpeg');
        $variant = $this->publishAndRun($this->createPost('Nos croissants', mediaIds: [$image->id]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(self::FB_PAGE_ID.'_77001', $variant->external_post_id, 'Identifiant de la publication (post_id), pas celui de la photo.');
        $sent = $this->sent[0];
        $this->assertSame(self::FB_PAGE_ID.'/photos', $sent['path']);
        $this->assertSame('Nos croissants', $sent['body']['caption']);
        $this->assertStringContainsString('/api/v1/media/'.$image->id.'/file/original', $sent['body']['url']);
        $this->assertStringContainsString('signature=', $sent['body']['url'], 'URL signée, de courte durée.');
    }

    public function test_several_photos_are_grouped_in_one_post_without_publishing_them_alone(): void
    {
        $ids = [$this->facebookMedia($this->user, 'image/jpeg')->id, $this->facebookMedia($this->user, 'image/png')->id, $this->facebookMedia($this->user, 'image/gif')->id];
        $variant = $this->publishAndRun($this->createPost('Album', mediaIds: $ids));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(self::POST_ID, $variant->external_post_id);
        $this->assertSame([self::FB_PAGE_ID.'/photos', self::FB_PAGE_ID.'/photos', self::FB_PAGE_ID.'/photos', self::FB_PAGE_ID.'/feed'], array_column($this->sent, 'path'));
        foreach (array_slice($this->sent, 0, 3) as $photo) {
            $this->assertSame('false', $photo['body']['published'], 'Photos envoyées non publiées.');
        }
        $feed = $this->sent[3]['body'];
        $this->assertSame('Album', $feed['message']);
        $this->assertSame(['{"media_fbid":"77001"}', '{"media_fbid":"77002"}', '{"media_fbid":"77003"}'], [$feed['attached_media[0]'], $feed['attached_media[1]'], $feed['attached_media[2]']]);
    }

    public function test_a_video_is_sent_by_signed_url(): void
    {
        $video = $this->facebookMedia($this->user, 'video/mp4', 8192);
        $variant = $this->publishAndRun($this->createPost('Visite de la boulangerie', mediaIds: [$video->id]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('880000000000001', $variant->external_post_id);
        $this->assertSame('https://www.facebook.com/'.self::FB_PAGE_ID.'/videos/880000000000001/', $variant->external_url);
        $this->assertSame(self::FB_PAGE_ID.'/videos', $this->sent[0]['path']);
        $this->assertSame('Visite de la boulangerie', $this->sent[0]['body']['description']);
        $this->assertStringContainsString('/api/v1/media/'.$video->id.'/file/original', $this->sent[0]['body']['file_url']);
    }

    // --- Refus avant envoi ----------------------------------------------------------------------

    public function test_unsupported_contents_are_refused_without_any_call(): void
    {
        $video = $this->facebookMedia($this->user, 'video/mp4');
        $image = $this->facebookMedia($this->user, 'image/jpeg');
        $webp = $this->facebookMedia($this->user, 'image/webp');
        foreach ([
            ['MIXED_MEDIA_NOT_SUPPORTED', [$image->id, $video->id], 'feed'],
            ['MEDIA_TYPE_NOT_SUPPORTED', [$webp->id], 'feed'],
            ['FORMAT_NOT_SUPPORTED', [$video->id], 'reel'],
        ] as [$code, $ids, $format]) {
            Queue::fake();
            $variant = $this->publishAndRun($this->createPost('Refus', mediaIds: $ids, format: $format));
            $this->assertSame(VariantStatus::Failed, $variant->status, $code);
            $this->assertSame($code, $variant->last_error_code);
        }
        $this->assertSame([], $this->sent);
    }

    public function test_not_configured_server_sends_nothing(): void
    {
        config(['services.facebook.client_secret' => null]);
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame('NOT_CONFIGURED', $variant->last_error_code);
        $this->assertSame([], $this->sent);
    }

    // --- Erreurs Facebook -----------------------------------------------------------------------

    public function test_a_revoked_page_token_flags_the_page_for_reconnection(): void
    {
        $this->respond(fn () => Http::response(['error' => ['message' => 'Error validating access token: The session has been invalidated.', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 460]], 400));
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame('TOKEN_INVALID', $variant->last_error_code);
        $this->assertFalse(PublicationAttempt::sole()->retryable);
        $this->assertSame(SocialAccountStatus::Expired, $this->account->fresh()->status);
        $this->assertSame('invalid', $this->account->tokens()->sole()->status->value);
    }

    public function test_graph_errors_are_classified(): void
    {
        foreach ([
            [['code' => 200, 'message' => 'Permissions error'], 403, 'PERMISSION_DENIED', VariantStatus::Failed],
            [['code' => 506, 'message' => 'Duplicate status message'], 400, 'DUPLICATE_CONTENT', VariantStatus::Failed],
            [['code' => 368, 'message' => 'The action attempted has been deemed abusive'], 400, 'POLICY_BLOCKED', VariantStatus::Failed],
            [['code' => 32, 'message' => 'Page request limit reached'], 400, 'RATE_LIMITED', VariantStatus::Queued],
            [['code' => 100, 'message' => 'Invalid parameter'], 400, 'INVALID_REQUEST', VariantStatus::Failed],
        ] as [$error, $status, $code, $expected]) {
            Queue::fake();
            $this->respond(fn () => Http::response(['error' => $error + ['type' => 'OAuthException']], $status));
            $variant = $this->publishAndRun($this->createPost("Erreur {$error['code']}"));

            $this->assertSame($expected, $variant->status, (string) $error['code']);
            $this->assertSame($code, $variant->last_error_code, (string) $error['code']);
        }
    }

    public function test_a_server_error_after_sending_is_an_unknown_result_never_retried(): void
    {
        $this->respond(fn () => Http::response(['error' => ['message' => 'An unknown error occurred', 'code' => 1]], 500));
        $variant = $this->publishAndRun($this->createPost());

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertFalse(PublicationAttempt::sole()->retryable, 'Résultat inconnu : jamais relancé automatiquement.');
    }
}
