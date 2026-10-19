<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
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
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Social\Concerns\LinkedInFixtures;
use Tests\Feature\Social\Concerns\TelegramFixtures;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Publication d'une vidéo de la médiathèque (Médias → « Publier sur les réseaux » → Composer) : mêmes appels
 * API que le frontend (POST /content/posts puis /publish), même moteur et mêmes adaptateurs que le Clipper.
 * La vidéo est référencée (post_media), jamais copiée. Faux YouTube et Telegram, aucun appel réel.
 */
class MediaLibraryPublishingTest extends TestCase
{
    use LinkedInFixtures, RefreshDatabase, TelegramFixtures, YouTubeFixtures;

    private User $user;

    private Media $video;

    private SocialAccount $youtube;

    private SocialAccount $telegram;

    /** Réponse du faux Telegram (null : succès). */
    private ?PromiseInterface $telegramResponse = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->youtube = $this->youtubeAccount($this->user);
        $this->telegram = $this->telegramAccount($this->user);
        $this->video = $this->videoMedia($this->user);

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
                return $this->telegramResponse ?? self::tgOk(['message_id' => 700]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), 'upload/youtube/v3/videos')) {
                return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=LIB1']);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), 'upload_id=LIB1')) {
                return Http::response(['kind' => 'youtube#video', 'id' => 'libVideo01', 'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'public']]);
            }

            return null;
        });
    }

    /** Enregistrement envoyé par le Composer (mode brouillon, puis /publish pour « Publier maintenant »). */
    private function save(array $targets, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'title' => 'Depuis la médiathèque',
            'body' => 'Ma vidéo',
            'media_ids' => [$this->video->id],
            'targets' => $targets,
        ], $overrides));
    }

    private function youtubeTarget(string $visibility = 'public'): array
    {
        return ['social_account_id' => $this->youtube->id, 'format' => 'video', 'options' => ['title' => 'Vidéo de la médiathèque', 'youtubeVisibility' => $visibility, 'madeForKids' => false]];
    }

    private function telegramTarget(): array
    {
        return ['social_account_id' => $this->telegram->id, 'format' => 'feed'];
    }

    private function publish(string $postId, ?int $lockVersion = null): TestResponse
    {
        return $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/publish", $lockVersion === null ? [] : ['lock_version' => $lockVersion]);
    }

    private function runJobs(): void
    {
        foreach (Queue::pushed(PublishPostVariantJob::class) as $job) {
            $job->handle(app(PublishingService::class));
        }
        Queue::fake(); // jobs déjà traités : une relance ne les rejoue pas
    }

    private function variant(string $postId, SocialAccount $account): PostVariant
    {
        return PostVariant::query()->where('post_id', $postId)->where('social_account_id', $account->id)->sole();
    }

    public function test_a_library_video_is_published_on_several_networks_by_reference_with_the_chosen_youtube_visibility(): void
    {
        $saved = $this->save([$this->youtubeTarget('public'), $this->telegramTarget()])->assertCreated();
        $postId = $saved->json('data.id');
        $this->assertSame([$this->video->id], Post::findOrFail($postId)->postMedia()->pluck('media_id')->all(), 'Média de la médiathèque référencé, pas copié.');

        $this->publish($postId, $saved->json('data.lock_version'))->assertOk();
        $this->runJobs();

        $this->assertSame(1, Media::query()->count(), 'Aucun nouveau fichier : la vidéo de la médiathèque est la source.');
        $this->assertSame('published', Post::findOrFail($postId)->status->value);
        $this->assertSame(['libVideo01', '700'], [$this->variant($postId, $this->youtube)->external_post_id, $this->variant($postId, $this->telegram)->external_post_id]);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'upload/youtube') && $r->data()['status']['privacyStatus'] === 'public');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendVideo'));
        $this->assertSame([false], PublicationAttempt::query()->whereNotNull('response_excerpt->privacy_mismatch')->pluck('response_excerpt')->map(fn ($e) => $e['privacy_mismatch'])->all());

        // Suivi par réseau : chaque variante avec son état, sans jeton.
        $detail = $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$postId}")->assertOk();
        $this->assertEqualsCanonicalizing(['published', 'published'], array_column($detail->json('data.variants'), 'status'));
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, $detail->getContent());
        $this->assertStringNotContainsString(self::BOT_TOKEN, $detail->getContent());
    }

    public function test_a_failure_on_one_network_keeps_the_others_and_retry_only_resends_the_failed_one(): void
    {
        $this->telegramResponse = self::tgError(400, 'Bad Request: wrong file identifier/HTTP URL specified');
        $postId = $this->save([$this->youtubeTarget(), $this->telegramTarget()])->assertCreated()->json('data.id');
        $this->publish($postId)->assertOk();
        $this->runJobs();

        $this->assertSame('partially_published', Post::findOrFail($postId)->status->value);
        $this->assertSame(VariantStatus::Published, $this->variant($postId, $this->youtube)->status);
        $failed = $this->variant($postId, $this->telegram);
        $this->assertSame(VariantStatus::Failed, $failed->status);
        $this->assertNotNull($failed->last_error_message);

        // « Réessayer » : seule la variante en échec repart ; YouTube n'est jamais renvoyé.
        $this->telegramResponse = null;
        $youtubeCalls = Http::recorded(fn (Request $r) => str_contains($r->url(), 'youtube'))->count();
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/retry")->assertOk();
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        $this->runJobs();

        $this->assertSame($youtubeCalls, Http::recorded(fn (Request $r) => str_contains($r->url(), 'youtube'))->count());
        $this->assertSame('published', Post::findOrFail($postId)->status->value);
        $this->assertSame(1, PublicationAttempt::query()->where('post_variant_id', $this->variant($postId, $this->youtube)->id)->count());
    }

    public function test_a_missing_unready_or_foreign_media_is_refused_before_anything_is_queued(): void
    {
        $other = User::factory()->create();
        $foreign = Media::factory()->for($other)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $processing = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'status' => 'processing']);
        $trashed = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $trashed->delete();

        foreach ([$foreign->id, $processing->id, $trashed->id, '01a00000-0000-7000-8000-000000000000'] as $mediaId) {
            $this->save([$this->youtubeTarget()], ['media_ids' => [$mediaId]])->assertUnprocessable()->assertJsonValidationErrors('media_ids.0');
        }
        $this->assertSame(0, Post::query()->count());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_foreign_social_account_is_refused(): void
    {
        $foreignAccount = $this->youtubeAccount(User::factory()->create());

        $this->save([['social_account_id' => $foreignAccount->id, 'format' => 'video', 'options' => ['title' => 'x']]])->assertUnprocessable();
        $this->assertSame(0, Post::query()->count());
    }

    public function test_a_disconnected_account_blocks_publication_and_a_refused_token_fails_only_that_network(): void
    {
        // Compte déconnecté : refus clair, rien n'est mis en file.
        $postId = $this->save([$this->youtubeTarget(), $this->telegramTarget()])->assertCreated()->json('data.id');
        $this->youtube->forceFill(['status' => 'expired'])->save();
        $this->publish($postId)->assertUnprocessable()->assertJsonValidationErrors('targets');
        Queue::assertNothingPushed();

        // Jeton expiré dont le renouvellement est refusé par Google : échec de YouTube seul, Telegram publié.
        $this->youtube->forceFill(['status' => 'connected'])->save();
        SocialToken::query()->update(['expires_at' => now()->subMinute()]);
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400), 'api.telegram.org/*' => self::tgOk(['message_id' => 701])]);
        $this->publish($postId)->assertOk();
        $this->runJobs();

        $this->assertSame(VariantStatus::Failed, $this->variant($postId, $this->youtube)->status);
        $this->assertSame('TOKEN_REVOKED', $this->variant($postId, $this->youtube)->last_error_code);
        $this->assertSame(VariantStatus::Published, $this->variant($postId, $this->telegram)->status);
        $this->assertSame('expired', $this->youtube->fresh()->status->value, 'Compte à reconnecter, signalé à l\'utilisateur.');
    }

    public function test_a_video_incompatible_with_a_platform_fails_there_without_any_upload(): void
    {
        // Telegram : vidéo non MP4 refusée avant tout envoi ; LinkedIn et X : médias non pris en charge.
        $mov = $this->telegramMedia($this->user, 'video/quicktime');
        $postId = $this->save([$this->telegramTarget()], ['media_ids' => [$mov->id]])->assertCreated()->json('data.id');
        $this->publish($postId)->assertOk();
        $this->runJobs();
        $this->assertSame(['failed', 'MEDIA_INCOMPATIBLE'], [$this->variant($postId, $this->telegram)->status->value, $this->variant($postId, $this->telegram)->last_error_code]);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.telegram.org'));

        $linkedin = SocialAccount::factory()->for($this->user)->create(['social_platform_id' => $this->configureLinkedIn()->id]);
        $postId = $this->save([['social_account_id' => $linkedin->id, 'format' => 'feed']])->assertCreated()->json('data.id');
        $this->publish($postId)->assertOk();
        $this->runJobs();
        $this->assertSame('MEDIA_NOT_SUPPORTED', $this->variant($postId, $linkedin)->last_error_code);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'linkedin'));
    }

    public function test_a_scheduled_library_video_is_sent_at_the_due_time_with_its_saved_visibility(): void
    {
        $this->save([$this->youtubeTarget('unlisted')], ['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(30)->toIso8601String()])->assertCreated();
        Queue::assertNothingPushed();

        $this->travel(31)->minutes();
        SocialToken::query()->update(['expires_at' => now()->addHour()]);
        $this->assertSame(1, app(PublishingService::class)->dispatchDue());
        $this->runJobs();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'upload/youtube') && $r->data()['status']['privacyStatus'] === 'unlisted');
        $this->assertSame('schedule', PublicationAttempt::sole()->trigger->value);
        $this->assertSame(0, app(PublishingService::class)->dispatchDue(), 'Échéance traitée une seule fois.');
    }

    public function test_a_double_publish_request_never_publishes_twice(): void
    {
        $saved = $this->save([$this->youtubeTarget()])->assertCreated();
        $postId = $saved->json('data.id');

        $this->publish($postId, $saved->json('data.lock_version'))->assertOk();
        $this->publish($postId, $saved->json('data.lock_version'))->assertStatus(409); // second clic, même version
        $this->publish($postId)->assertStatus(409); // sans version : déjà en file
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        $this->runJobs();

        $this->publish($postId)->assertStatus(409); // déjà publiée
        $this->assertSame(1, PublicationAttempt::query()->count());
        Http::assertSentCount(2); // une ouverture de session + un envoi
    }
}
