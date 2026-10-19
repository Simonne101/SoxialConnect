<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\Adapters\YouTubePublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\PublishingService;
use App\Jobs\PublishPostVariantJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * YouTube dans le moteur de publication Phase 1 : PublisherRegistry → YouTubePublisher →
 * (faux) YouTube Data API → PublishResult → PublishingService. Aucun appel réel à Google.
 */
class YouTubePublishingTest extends TestCase
{
    use RefreshDatabase, YouTubeFixtures;

    private const UPLOAD_SESSION = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=SESSION123';

    private User $user;

    private SocialAccount $account;

    private Media $video;

    /** Contenu reçu par le faux YouTube (lu pendant la requête : le flux est fermé ensuite). */
    private ?string $uploadedBody = null;

    /** Visibilité reçue par le faux YouTube à l'ouverture de session (renvoyée telle quelle par défaut). */
    private ?string $sentPrivacy = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->account = $this->youtubeAccount($this->user);
        $this->video = $this->videoMedia($this->user);
    }

    /**
     * Faux YouTube : ouverture de session (POST) puis envoi du fichier (PUT).
     *
     * @param  callable(): PromiseInterface|null  $upload
     */
    private function fakeYouTube(?PromiseInterface $session = null, ?callable $upload = null): void
    {
        Http::fake(function (Request $request) use ($session, $upload) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'upload/youtube/v3/videos')) {
                $this->sentPrivacy = $request->data()['status']['privacyStatus'] ?? null;

                return $session ?? Http::response('', 200, ['Location' => self::UPLOAD_SESSION]);
            }
            if ($request->method() === 'PUT' && $request->url() === self::UPLOAD_SESSION) {
                $this->uploadedBody = $request->body();

                return $upload ? $upload() : Http::response([
                    'kind' => 'youtube#video',
                    'id' => 'dQw4w9WgXcQ',
                    'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => $this->sentPrivacy],
                ], 200);
            }

            return null;
        });
    }

    private function createPost(array $overrides = []): Post
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'title' => 'Titre de la publication',
            'body' => "Première ligne\nDescription complète <b>",
            'media_ids' => [$this->video->id],
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'video', 'options' => ['title' => 'Ma vidéo', 'hashtags' => ['tuto', 'laravel']]]],
        ], $overrides))->assertCreated()->json('data.id');

        return Post::findOrFail($id);
    }

    private function publishAndRun(Post $post, int $jobIndex = 0): void
    {
        app(PublishingService::class)->publishNow($this->user, $post);
        Queue::pushed(PublishPostVariantJob::class)->values()[$jobIndex]->handle(app(PublishingService::class));
    }

    private function variant(Post $post): PostVariant
    {
        return $post->variants()->firstOrFail();
    }

    // --- Succès -----------------------------------------------------------------------------

    public function test_youtube_success_publishes_with_the_real_video_id(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $post = $this->createPost();
        $this->publishAndRun($post);

        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame('dQw4w9WgXcQ', $attempt->external_post_id);
        $variant = $this->variant($post)->fresh();
        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('dQw4w9WgXcQ', $variant->external_post_id);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $variant->external_url);
        $this->assertSame('published', $post->fresh()->status->value);
        $this->assertSame('private', $attempt->response_excerpt['privacy_status']);
        $this->assertFalse($attempt->response_excerpt['privacy_mismatch']);

        // Session : jeton serveur, métadonnées mappées, visibilité absente → PRIVÉE, taille et type du fichier.
        Http::assertSent(function (Request $r) {
            if ($r->method() !== 'POST') {
                return false;
            }
            $data = $r->data();

            return $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN)
                && $r->hasHeader('X-Upload-Content-Type', 'video/mp4')
                && $r->hasHeader('X-Upload-Content-Length', '2048')
                && str_contains($r->url(), 'uploadType=resumable')
                && $data['snippet']['title'] === 'Ma vidéo'
                && $data['snippet']['description'] === "Première ligne\nDescription complète b"
                && $data['snippet']['tags'] === ['tuto', 'laravel']
                && $data['status']['privacyStatus'] === 'private';
        });
        // Fichier lu depuis le stockage privé et envoyé tel quel.
        $this->assertSame(str_repeat('v', 2048), $this->uploadedBody);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Content-Type', 'video/mp4'));
    }

    public function test_title_falls_back_to_the_post_title_then_the_first_line(): void
    {
        $publisher = app(YouTubePublisher::class);
        $request = fn (array $options, ?string $title) => new PublishRequest(
            'p', 'v', 'a', 'youtube', 'UC', 'video', "Ligne un\nLigne deux", $options, [], 'k', 1, $title,
        );

        $this->assertSame('Titre du post', $publisher->metadata($request([], 'Titre du post'))['snippet']['title']);
        $this->assertSame('Ligne un', $publisher->metadata($request([], null))['snippet']['title']);
    }

    public function test_an_already_published_video_is_never_uploaded_twice(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $post = $this->createPost();
        $this->publishAndRun($post);

        // Rejeu du job, relance, nouvelle demande : aucun second envoi.
        Queue::pushed(PublishPostVariantJob::class)->first()->handle(app(PublishingService::class));
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertStatus(409);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/publish")->assertStatus(409);

        $this->assertSame(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT')->count());
        $this->assertSame(1, PublicationAttempt::count());
    }

    // --- Échecs ------------------------------------------------------------------------------

    public function test_quota_exceeded_is_temporary_and_retried_by_the_engine(): void
    {
        Queue::fake();
        $this->fakeYouTube(Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'quotaExceeded']]]], 403));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $attempt = PublicationAttempt::sole();
        $this->assertSame('QUOTA_EXCEEDED', $attempt->error_code);
        $this->assertTrue($attempt->retryable);
        $this->assertSame(403, $attempt->http_status);
        $this->assertSame(VariantStatus::Queued, $this->variant($post)->fresh()->status);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'auto_retry');
        $this->assertSame(0, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT')->count());
    }

    public function test_permission_denied_fails_without_retry(): void
    {
        Queue::fake();
        $this->fakeYouTube(Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'insufficientPermissions']]]], 403));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('PERMISSION_DENIED', PublicationAttempt::sole()->error_code);
        $this->assertFalse(PublicationAttempt::sole()->retryable);
        $this->assertSame('failed', $post->fresh()->status->value);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
    }

    public function test_http_error_is_a_permanent_failure(): void
    {
        Queue::fake();
        $this->fakeYouTube(Http::response(['error' => ['code' => 400, 'errors' => [['reason' => 'invalidTitle']]]], 400));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('INVALID_REQUEST', PublicationAttempt::sole()->error_code);
        $this->assertSame(VariantStatus::Failed, $this->variant($post)->fresh()->status);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
    }

    public function test_server_error_during_upload_is_temporary(): void
    {
        // Envoi du fichier refusé en 503 : aucune vidéo créée, nouvel essai automatique.
        Queue::fake();
        $this->fakeYouTube(null, fn () => Http::response('', 503));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('PLATFORM_UNAVAILABLE', $this->variant($post)->fresh()->last_error_code);
        $this->assertSame(VariantStatus::Queued, $this->variant($post)->fresh()->status);
        Queue::assertPushed(PublishPostVariantJob::class, fn ($job) => $job->trigger === 'auto_retry');
    }

    public function test_expired_token_is_refreshed_before_upload(): void
    {
        Queue::fake();
        $this->account->tokens()->update(['expires_at' => now()->subMinute()]);
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'ya29.fresh', 'expires_in' => 3599]);
            }
            if ($request->method() === 'POST') {
                return Http::response('', 200, ['Location' => self::UPLOAD_SESSION]);
            }

            return Http::response(['kind' => 'youtube#video', 'id' => 'NEWVIDEO01', 'status' => ['uploadStatus' => 'uploaded']]);
        });
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('NEWVIDEO01', PublicationAttempt::sole()->external_post_id);
        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->hasHeader('Authorization', 'Bearer ya29.fresh'));
    }

    public function test_revoked_authorization_fails_and_asks_for_reconnection(): void
    {
        Queue::fake();
        $this->account->tokens()->update(['expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('TOKEN_REVOKED', PublicationAttempt::sole()->error_code);
        $this->assertSame('failed', $post->fresh()->status->value);
        $this->assertSame('expired', $this->account->fresh()->status->value);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'upload/youtube'));
    }

    public function test_invalid_api_response_after_upload_is_an_unknown_outcome(): void
    {
        Queue::fake();
        $this->fakeYouTube(null, fn () => Http::response(['unexpected' => true], 200));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->assertSame(PublishingService::UNKNOWN_CODE, $this->variant($post)->fresh()->last_error_code);
        $this->assertNull($this->variant($post)->fresh()->external_post_id);
        Queue::assertPushed(PublishPostVariantJob::class, 1); // aucune relance aveugle
    }

    public function test_timeout_during_upload_is_an_unknown_outcome(): void
    {
        Queue::fake();
        $this->fakeYouTube(null, fn () => throw new ConnectionException('Operation timed out'));
        $post = $this->createPost();
        $this->publishAndRun($post);

        $this->assertSame('unknown', PublicationAttempt::sole()->status->value);
        $this->assertSame('failed', $post->fresh()->status->value);
    }

    public function test_missing_video_file_fails_before_any_youtube_call(): void
    {
        Queue::fake();
        $post = $this->createPost();
        Storage::disk('media')->delete($this->video->path);
        $this->publishAndRun($post);

        $this->assertSame('MEDIA_NOT_FOUND', PublicationAttempt::sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_an_image_is_refused_for_youtube(): void
    {
        Queue::fake();
        $image = Media::factory()->for($this->user)->create();
        $post = $this->createPost(['media_ids' => [$image->id]]);
        $this->publishAndRun($post);

        $this->assertSame('MEDIA_INCOMPATIBLE', PublicationAttempt::sole()->error_code);
        Http::assertNothingSent();
    }

    public function test_missing_google_credentials_fall_back_to_not_configured(): void
    {
        Queue::fake();
        $post = $this->createPost();
        config(['services.youtube.client_id' => null]);
        $this->publishAndRun($post);

        $this->assertSame('NOT_CONFIGURED', PublicationAttempt::sole()->error_code);
        $this->assertSame('failed', $post->fresh()->status->value);
        Http::assertNothingSent();
    }

    public function test_retry_after_a_failure_creates_a_new_attempt_that_can_succeed(): void
    {
        Queue::fake();
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            if ($request->method() === 'POST') {
                return ++$calls === 1
                    ? Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403)
                    : Http::response('', 200, ['Location' => self::UPLOAD_SESSION]);
            }

            return Http::response(['kind' => 'youtube#video', 'id' => 'RETRYVIDEO1', 'status' => ['uploadStatus' => 'uploaded']]);
        });
        $post = $this->createPost();
        $this->publishAndRun($post);
        $this->assertSame('failed', $post->fresh()->status->value);

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/retry")->assertOk();
        Queue::pushed(PublishPostVariantJob::class)->values()[1]->handle(app(PublishingService::class));

        $attempts = PublicationAttempt::orderBy('attempt_number')->get();
        $this->assertSame(['failed', 'succeeded'], $attempts->map(fn ($a) => $a->status->value)->all());
        $this->assertSame('RETRYVIDEO1', $attempts[1]->external_post_id);
        $this->assertSame('published', $post->fresh()->status->value);
    }

    public function test_tokens_never_appear_in_attempts_or_api_responses(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $post = $this->createPost();
        $this->publishAndRun($post);

        $json = $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}/attempts")->getContent()
            .$this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}")->getContent()
            .json_encode(PublicationAttempt::sole()->toArray());
        foreach ([self::ACCESS_TOKEN, self::REFRESH_TOKEN, 'test-client-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    // --- Visibilité (ADR-065) ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function visibilities(): array
    {
        return ['publique' => ['public'], 'non répertoriée' => ['unlisted'], 'privée' => ['private']];
    }

    #[DataProvider('visibilities')]
    public function test_the_visibility_chosen_in_the_composer_is_sent_and_confirmed(string $visibility): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $post = $this->createPost(['targets' => [['social_account_id' => $this->account->id, 'format' => 'video', 'options' => ['youtubeVisibility' => $visibility]]]]);

        $this->publishAndRun($post);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['status']['privacyStatus'] === $visibility);
        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame([$visibility, $visibility, false], [$attempt->response_excerpt['privacy_requested'], $attempt->response_excerpt['privacy_status'], $attempt->response_excerpt['privacy_mismatch']]);
        $this->assertArrayNotHasKey('warnings', $attempt->response_excerpt);
        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}/attempts")->assertOk()->assertJsonPath('data.0.warnings', []);
    }

    public function test_a_missing_or_invalid_visibility_falls_back_to_private_never_public(): void
    {
        $publisher = app(YouTubePublisher::class);
        $privacy = fn (array $options) => $publisher->metadata(new PublishRequest('p', 'v', 'a', 'youtube', 'UC', 'video', 'Texte', $options, [], 'k', 1))['status']['privacyStatus'];

        foreach ([[], ['youtubeVisibility' => null], ['youtubeVisibility' => ''], ['youtubeVisibility' => 'PUBLIC'], ['youtubeVisibility' => ' public'],
            ['youtubeVisibility' => 'publique'], ['youtubeVisibility' => ['public']], ['youtubeVisibility' => true], ['privacy' => 'public']] as $options) {
            $this->assertSame('private', $privacy($options), json_encode($options));
        }

        // Une valeur hors liste blanche est refusée dès l'enregistrement.
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft', 'body' => 'Texte', 'media_ids' => [$this->video->id],
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'video', 'options' => ['youtubeVisibility' => 'publique']]],
        ])->assertUnprocessable()->assertJsonValidationErrors('targets.0.options.youtubeVisibility');
    }

    public function test_made_for_kids_is_sent_alongside_the_visibility_only_when_set(): void
    {
        $publisher = app(YouTubePublisher::class);
        $status = fn (array $options) => $publisher->metadata(new PublishRequest('p', 'v', 'a', 'youtube', 'UC', 'video', 'Texte', $options, [], 'k', 1))['status'];

        $this->assertSame(['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => true], $status(['youtubeVisibility' => 'public', 'madeForKids' => true]));
        $this->assertSame(['privacyStatus' => 'unlisted', 'selfDeclaredMadeForKids' => false], $status(['youtubeVisibility' => 'unlisted', 'madeForKids' => false]));
        $this->assertSame(['privacyStatus' => 'private'], $status([]));
    }

    public function test_a_visibility_changed_by_youtube_stays_a_success_with_a_traced_and_visible_warning(): void
    {
        Queue::fake();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = [$e->message, $e->context];
        });
        $responses = [['uploadStatus' => 'uploaded', 'privacyStatus' => 'private'], ['uploadStatus' => 'uploaded']];
        $this->fakeYouTube(upload: function () use (&$responses) {
            return Http::response(['kind' => 'youtube#video', 'id' => 'vid'.count($responses), 'status' => array_shift($responses)], 200);
        });
        $targets = ['targets' => [['social_account_id' => $this->account->id, 'format' => 'video', 'options' => ['youtubeVisibility' => 'public']]]];
        $post = $this->createPost($targets);

        $this->publishAndRun($post);

        // Demandée « public », appliquée « private » (ex. projet Google non audité) : réussite, écart tracé.
        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame(VariantStatus::Published, $this->variant($post)->fresh()->status);
        $this->assertNull($this->variant($post)->fresh()->last_error_code);
        $this->assertSame(['public', 'private', true], [$attempt->response_excerpt['privacy_requested'], $attempt->response_excerpt['privacy_status'], $attempt->response_excerpt['privacy_mismatch']]);
        $this->assertContains(['publishing.youtube_visibility_mismatch', ['post_variant_id' => $this->variant($post)->id, 'requested' => 'public', 'applied' => 'private']], $logged);

        $res = $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}/attempts")->assertOk();
        $res->assertJsonPath('data.0.warnings.0.code', 'VISIBILITY_MISMATCH');
        $this->assertStringContainsString('« Privée » au lieu de « Publique »', $res->json('data.0.warnings.0.message'));
        $this->assertArrayNotHasKey('response_excerpt', $res->json('data.0'));

        // Visibilité absente de la réponse : non confirmée, signalée aussi.
        $second = $this->createPost($targets);
        $this->publishAndRun($second, 1);
        $excerpt = PublicationAttempt::query()->where('post_variant_id', $this->variant($second)->id)->sole()->response_excerpt;
        $this->assertSame([null, true], [$excerpt['privacy_status'], $excerpt['privacy_mismatch']]);
        $this->assertStringContainsString('n\'a pas confirmé', $excerpt['warnings'][0]['message']);
    }

    public function test_a_scheduled_publication_sends_the_visibility_saved_last_before_execution(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $payload = fn (string $visibility) => [
            'mode' => 'schedule', 'scheduled_at' => now()->addHour()->toIso8601String(), 'title' => 'Programmée', 'body' => 'Texte',
            'media_ids' => [$this->video->id],
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'video', 'options' => ['youtubeVisibility' => $visibility, 'madeForKids' => false]]],
        ];
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $payload('private'))->assertCreated()->json('data.id');
        // Modifiée avant l'échéance : c'est la dernière valeur enregistrée qui part.
        $this->actingAs($this->user)->fromSpa()->putJson("/api/v1/content/posts/{$id}", $payload('unlisted'))->assertOk();

        $this->travel(2)->hours();
        SocialToken::query()->update(['expires_at' => now()->addHour()]); // jeton encore valide à l'échéance
        $this->assertSame(1, app(PublishingService::class)->dispatchDue());
        Queue::pushed(PublishPostVariantJob::class)->first()->handle(app(PublishingService::class));

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data()['status'] === ['privacyStatus' => 'unlisted', 'selfDeclaredMadeForKids' => false]);
        $this->assertSame('unlisted', PublicationAttempt::sole()->response_excerpt['privacy_status']);
        $this->assertSame('schedule', PublicationAttempt::sole()->trigger->value);
    }

    public function test_attempts_recorded_before_the_visibility_report_expose_no_warning(): void
    {
        Queue::fake();
        $this->fakeYouTube();
        $post = $this->createPost();
        $this->publishAndRun($post);
        PublicationAttempt::query()->update(['response_excerpt' => ['upload_status' => 'uploaded', 'privacy_status' => 'private']]); // forme antérieure

        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$post->id}/attempts")->assertOk()->assertJsonPath('data.0.warnings', []);
    }
}
