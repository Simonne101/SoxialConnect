<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\PublishingService;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Jobs\PublishPostVariantJob;
use App\Jobs\ReconcilePublicationJob;
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
use Tests\Feature\Social\Concerns\InstagramFixtures;
use Tests\TestCase;

/**
 * Instagram dans le moteur de publication : conteneur (/media) → publication (/media_publish) ;
 * vidéos : Processing(conteneur) → ReconcilePublicationJob → FINISHED → media_publish. Aucun appel réel.
 */
class InstagramPublishingTest extends TestCase
{
    use FacebookFixtures, InstagramFixtures, RefreshDatabase;

    private const MEDIA_ID = '18000000000000001';

    private User $user;

    private SocialAccount $account;

    /** @var list<array{method: string, path: string, headers: array<string, mixed>, body: array<string, mixed>}> */
    private array $sent = [];

    private ?Closure $responder = null;

    private string $containerStatus = 'FINISHED';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('media');
        $this->user = User::factory()->create();
        $this->account = $this->instagramAccount($this->user);
        $container = 0;
        $this->respond(function (string $method, string $path) use (&$container) {
            return match (true) {
                $path === self::IG_USER_ID.'/media' => Http::response(['id' => '1790000000000000'.(++$container)]),
                $path === self::IG_USER_ID.'/media_publish' => Http::response(['id' => self::MEDIA_ID]),
                $path === self::MEDIA_ID => Http::response(['permalink' => 'https://www.instagram.com/p/AbCdEf/', 'id' => self::MEDIA_ID]),
                $method === 'GET' && str_starts_with($path, '1790000') => Http::response(['status_code' => $this->containerStatus, 'status' => $this->containerStatus === 'ERROR' ? 'Error: 2207026' : 'Finished: Media has been uploaded and it is ready to be published.', 'id' => $path]),
                default => Http::response(['error' => ['message' => 'Unknown path', 'code' => 100]], 400),
            };
        });
        Http::fake(function (Request $request) {
            if (! str_starts_with($request->url(), self::IG_GRAPH)) {
                return null;
            }
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/v23.0/'));
            $this->sent[] = ['method' => $request->method(), 'path' => $path, 'headers' => $request->headers(), 'body' => $request->data()];

            return ($this->responder)($request->method(), $path);
        });
    }

    private function respond(Closure $responder): void
    {
        $this->responder = $responder;
    }

    private function createPost(string $body = 'Bonjour Instagram', array $options = [], array $mediaIds = [], string $format = 'feed'): Post
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

    private function runLastReconcile(): void
    {
        Queue::pushed(ReconcilePublicationJob::class)->last()->handle(app(PublishingService::class));
    }

    /** @return list<string> */
    private function paths(): array
    {
        return array_map(fn ($s) => $s['method'].' '.$s['path'], $this->sent);
    }

    // --- Photos --------------------------------------------------------------------------------

    public function test_a_photo_is_published_in_two_steps_with_its_caption_and_permalink(): void
    {
        $image = $this->facebookMedia($this->user, 'image/jpeg');
        $variant = $this->publishAndRun($this->createPost('Nos croissants', ['hashtags' => ['pain']], [$image->id]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame(self::MEDIA_ID, $variant->external_post_id);
        $this->assertSame('https://www.instagram.com/p/AbCdEf/', $variant->external_url);
        $this->assertSame(['POST '.self::IG_USER_ID.'/media', 'POST '.self::IG_USER_ID.'/media_publish', 'GET '.self::MEDIA_ID], $this->paths());

        $container = $this->sent[0];
        $this->assertSame(['Bearer '.self::IG_TOKEN], $container['headers']['Authorization']);
        $this->assertSame("Nos croissants\n\n#pain", $container['body']['caption']);
        $this->assertStringContainsString('/api/v1/media/'.$image->id.'/file/original', $container['body']['image_url']);
        $this->assertSame('17900000000000001', $this->sent[1]['body']['creation_id']);
    }

    public function test_a_carousel_groups_photo_containers(): void
    {
        $ids = [$this->facebookMedia($this->user, 'image/jpeg')->id, $this->facebookMedia($this->user, 'image/jpeg')->id];
        $variant = $this->publishAndRun($this->createPost('Album', mediaIds: $ids, format: 'carousel'));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('true', $this->sent[0]['body']['is_carousel_item']);
        $this->assertSame('true', $this->sent[1]['body']['is_carousel_item']);
        $parent = $this->sent[2]['body'];
        $this->assertSame('CAROUSEL', $parent['media_type']);
        $this->assertSame('17900000000000001,17900000000000002', $parent['children']);
        $this->assertSame('Album', $parent['caption']);
        $this->assertSame('17900000000000003', $this->sent[3]['body']['creation_id']);
    }

    // --- Vidéos (suivi) ------------------------------------------------------------------------

    public function test_a_reel_is_tracked_until_ready_then_published(): void
    {
        $video = $this->facebookMedia($this->user, 'video/mp4', 8192);
        $cover = $this->facebookMedia($this->user, 'image/jpeg');
        $post = $this->createPost('Visite', ['coverMediaId' => $cover->id], [$video->id], 'reel');
        $this->containerStatus = 'IN_PROGRESS';
        $variant = $this->publishAndRun($post);

        // Conteneur créé, rien de publié : suivi programmé.
        $this->assertSame(VariantStatus::Publishing, $variant->status);
        $this->assertSame('in_progress', PublicationAttempt::sole()->status->value);
        $reel = $this->sent[0]['body'];
        $this->assertSame('REELS', $reel['media_type']);
        $this->assertSame('true', $reel['share_to_feed']);
        $this->assertStringContainsString('/api/v1/media/'.$video->id.'/file/original', $reel['video_url']);
        $this->assertStringContainsString('/api/v1/media/'.$cover->id.'/file/original', $reel['cover_url']);
        Queue::assertPushed(ReconcilePublicationJob::class, fn ($job) => $job->trackingId === '17900000000000001');

        // Toujours en préparation : nouvelle vérification, aucune publication.
        $this->runLastReconcile();
        $this->assertNotContains('POST '.self::IG_USER_ID.'/media_publish', $this->paths());

        // Prêt : publication et identifiant définitif.
        $this->containerStatus = 'FINISHED';
        $this->runLastReconcile();
        $this->assertSame(VariantStatus::Published, $variant->fresh()->status);
        $this->assertSame(self::MEDIA_ID, $variant->fresh()->external_post_id);
        $this->assertSame(1, count(array_filter($this->paths(), fn ($p) => $p === 'POST '.self::IG_USER_ID.'/media_publish')));
    }

    public function test_a_video_rejected_during_processing_fails_without_publishing(): void
    {
        $video = $this->facebookMedia($this->user, 'video/mp4');
        $this->containerStatus = 'ERROR';
        $variant = $this->publishAndRun($this->createPost('Vidéo', mediaIds: [$video->id], format: 'reel'));
        $this->runLastReconcile();

        $this->assertSame(VariantStatus::Failed, $variant->fresh()->status);
        $this->assertSame('MEDIA_PROCESSING_FAILED', $variant->fresh()->last_error_code);
        $this->assertNotContains('POST '.self::IG_USER_ID.'/media_publish', $this->paths());
    }

    // --- Refus avant envoi ----------------------------------------------------------------------

    public function test_unsupported_contents_are_refused_without_any_call(): void
    {
        $png = $this->facebookMedia($this->user, 'image/png');
        $jpeg = $this->facebookMedia($this->user, 'image/jpeg');
        $video = $this->facebookMedia($this->user, 'video/mp4');
        foreach ([
            ['MEDIA_REQUIRED', [], 'feed', 'Texte seul'],
            ['MEDIA_TYPE_NOT_SUPPORTED', [$png->id], 'feed', 'PNG'],
            ['REEL_REQUIRES_ONE_VIDEO', [$jpeg->id], 'reel', 'Reel photo'],
            ['CAROUSEL_TOO_SMALL', [$jpeg->id], 'carousel', 'Carrousel 1'],
            ['CAROUSEL_VIDEO_NOT_SUPPORTED', [$jpeg->id, $video->id], 'carousel', 'Carrousel vidéo'],
            ['TOO_MANY_HASHTAGS', [$jpeg->id], 'feed', implode(' ', array_map(fn ($i) => "#tag{$i}", range(1, 31)))],
        ] as [$code, $ids, $format, $body]) {
            Queue::fake();
            $variant = $this->publishAndRun($this->createPost($body, mediaIds: $ids, format: $format));
            $this->assertSame(VariantStatus::Failed, $variant->status, $code);
            $this->assertSame($code, $variant->last_error_code);
        }
        $this->assertSame([], $this->sent);
    }

    // --- Erreurs Instagram ----------------------------------------------------------------------

    public function test_a_revoked_token_flags_the_account_and_the_daily_limit_is_temporary(): void
    {
        $image = $this->facebookMedia($this->user, 'image/jpeg');
        $this->respond(fn () => Http::response(['error' => ['message' => 'Application request limit reached', 'code' => 9, 'error_subcode' => 2207042]], 400));
        $variant = $this->publishAndRun($this->createPost('Limite', mediaIds: [$image->id]));
        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertSame('PUBLISH_LIMIT_REACHED', $variant->last_error_code);

        Queue::fake();
        $this->respond(fn () => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400));
        $variant = $this->publishAndRun($this->createPost('Jeton', mediaIds: [$image->id]));
        $this->assertSame('TOKEN_INVALID', $variant->last_error_code);
        $this->assertSame(SocialAccountStatus::Expired, $this->account->fresh()->status);
    }

    public function test_a_rejected_media_file_is_explained(): void
    {
        $image = $this->facebookMedia($this->user, 'image/jpeg');
        $this->respond(fn () => Http::response(['error' => ['message' => 'The aspect ratio is not supported.', 'code' => 36003, 'error_subcode' => 2207009, 'error_user_msg' => 'Format d\'image non pris en charge.']], 400));
        $variant = $this->publishAndRun($this->createPost('Ratio', mediaIds: [$image->id]));

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('MEDIA_REJECTED', $variant->last_error_code);
    }
}
