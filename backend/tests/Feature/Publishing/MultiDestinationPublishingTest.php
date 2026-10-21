<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\PublishingService;
use App\Domain\Social\Enums\TokenKind;
use App\Jobs\PublishPostVariantJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Social\Concerns\LinkedInFixtures;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Une publication vers CINQ chaînes YouTube (et d'autres réseaux) : une variante, un job, une tentative, un
 * jeton et un identifiant distant par destination ; succès partiel, relance ciblée, programmation et
 * isolation. Faux Google (Http::fake), aucune publication réelle.
 */
class MultiDestinationPublishingTest extends TestCase
{
    use LinkedInFixtures, RefreshDatabase, YouTubeFixtures;

    private User $user;

    private Media $video;

    /** @var list<SocialAccount> */
    private array $channels = [];

    /** @var list<string> jetons présentés à l'ouverture des sessions d'envoi */
    private array $uploadTokens = [];

    /** Jetons refusés par le faux YouTube (403, échec définitif). */
    private array $rejectedTokens = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->configureYouTube();
        $this->video = $this->videoMedia($this->user);
        foreach (range(1, 5) as $n) {
            $this->channels[] = $this->channel($this->user, "UC_channel_{$n}", "token-{$n}", "Chaîne {$n}");
        }

        Http::fake(function (Request $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'upload/youtube/v3/videos')) {
                $token = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');
                $this->uploadTokens[] = $token;
                if (in_array($token, $this->rejectedTokens, true)) {
                    return Http::response(['error' => ['code' => 403, 'errors' => [['reason' => 'forbidden']]]], 403);
                }

                return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id='.$token]);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), 'upload_id=')) {
                $token = Str::after($request->url(), 'upload_id=');

                return Http::response(['kind' => 'youtube#video', 'id' => 'vid-'.$token, 'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'public']]);
            }

            return null;
        });
    }

    private function channel(User $owner, string $channelId, string $token, string $name): SocialAccount
    {
        $account = SocialAccount::factory()->for($owner)->create([
            'social_platform_id' => $this->configureYouTube()->id,
            'external_account_id' => $channelId,
            'account_type' => 'channel',
            'display_name' => $name,
        ]);
        SocialToken::create([
            'social_account_id' => $account->id, 'token_kind' => TokenKind::User, 'access_token_encrypted' => $token,
            'refresh_token_encrypted' => "refresh-{$token}", 'expires_at' => now()->addHours(3),
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'], 'status' => 'active',
        ]);

        return $account;
    }

    /** @param list<SocialAccount> $accounts */
    private function save(array $accounts, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'body' => 'Une vidéo pour toutes mes chaînes',
            'media_ids' => [$this->video->id],
            'targets' => array_map(fn (SocialAccount $a) => ['social_account_id' => $a->id, 'format' => 'video', 'options' => ['title' => 'Ma vidéo', 'youtubeVisibility' => 'public']], $accounts),
        ], $overrides));
    }

    private function runJobs(): void
    {
        $jobs = Queue::pushed(PublishPostVariantJob::class);
        Queue::fake(); // jobs consommés : une relance ne les rejoue pas
        foreach ($jobs as $job) {
            $job->handle(app(PublishingService::class));
        }
    }

    private function variantOf(string $postId, SocialAccount $account): PostVariant
    {
        return PostVariant::query()->where('post_id', $postId)->where('social_account_id', $account->id)->sole();
    }

    public function test_five_channels_get_five_independent_publications_with_their_own_token_and_remote_id(): void
    {
        $postId = $this->save($this->channels)->assertCreated()->assertJsonCount(5, 'data.variants')->json('data.id');

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/publish")->assertOk();
        Queue::assertPushed(PublishPostVariantJob::class, 5);
        $this->runJobs();

        $this->assertEqualsCanonicalizing(['token-1', 'token-2', 'token-3', 'token-4', 'token-5'], $this->uploadTokens, 'Chaque chaîne publiée une fois, avec SON jeton.');
        foreach ($this->channels as $n => $channel) {
            $variant = $this->variantOf($postId, $channel);
            $this->assertSame(VariantStatus::Published, $variant->status);
            $this->assertSame('vid-token-'.($n + 1), $variant->external_post_id);
            $this->assertSame(1, PublicationAttempt::query()->where('post_variant_id', $variant->id)->where('social_account_id', $channel->id)->count());
        }
        $this->assertSame('published', Post::findOrFail($postId)->status->value);

        $statuses = collect($this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$postId}")->assertOk()->json('data.variants'))
            ->pluck('status', 'social_account_id');
        $this->assertCount(5, $statuses);
        $this->assertSame(['published'], $statuses->unique()->values()->all());
    }

    public function test_partial_success_keeps_the_published_channels_and_retry_only_resends_the_failed_one(): void
    {
        $this->rejectedTokens = ['token-3'];
        $postId = $this->save($this->channels)->json('data.id');
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/publish")->assertOk();
        $this->runJobs();

        $this->assertSame('partially_published', Post::findOrFail($postId)->status->value);
        $this->assertSame(VariantStatus::Failed, $this->variantOf($postId, $this->channels[2])->status);
        $this->assertNotNull($this->variantOf($postId, $this->channels[2])->last_error_message);
        foreach ([0, 1, 3, 4] as $i) {
            $this->assertSame(VariantStatus::Published, $this->variantOf($postId, $this->channels[$i])->status);
        }

        // Relance : seule la chaîne 3 repart ; aucune des quatre autres n'est republiée.
        $this->rejectedTokens = [];
        $this->uploadTokens = [];
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/retry")->assertOk();
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        $this->runJobs();

        $this->assertSame(['token-3'], $this->uploadTokens);
        $this->assertSame('published', Post::findOrFail($postId)->status->value);
        $this->assertSame(2, PublicationAttempt::query()->where('post_variant_id', $this->variantOf($postId, $this->channels[2])->id)->count());
        $this->assertSame(1, PublicationAttempt::query()->where('post_variant_id', $this->variantOf($postId, $this->channels[0])->id)->count());
    }

    public function test_an_expired_authorisation_on_one_channel_fails_only_that_destination(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        SocialToken::query()->where('social_account_id', $this->channels[1]->id)->update(['expires_at' => now()->subMinute()]);
        $postId = $this->save($this->channels)->json('data.id');

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/publish")->assertOk();
        $this->runJobs();

        $failed = $this->variantOf($postId, $this->channels[1]);
        $this->assertSame([VariantStatus::Failed, 'TOKEN_REVOKED'], [$failed->status, $failed->last_error_code]);
        $this->assertSame('expired', $this->channels[1]->fresh()->status->value);
        $this->assertEqualsCanonicalizing(['token-1', 'token-3', 'token-4', 'token-5'], $this->uploadTokens);
        $this->assertSame('partially_published', Post::findOrFail($postId)->status->value);
    }

    public function test_a_scheduled_post_processes_each_destination_once_at_the_due_time(): void
    {
        $this->save($this->channels, ['mode' => 'schedule', 'scheduled_at' => now()->addMinutes(20)->toIso8601String()])->assertCreated();
        $this->assertSame(0, app(PublishingService::class)->dispatchDue(), 'Rien avant l\'échéance.');

        $this->travel(21)->minutes();
        $this->assertSame(5, app(PublishingService::class)->dispatchDue());
        $jobs = Queue::pushed(PublishPostVariantJob::class);
        $this->runJobs();
        $this->assertSame(5, PublicationAttempt::query()->where('trigger', 'schedule')->count());
        $this->assertSame(5, PostVariant::query()->where('status', 'published')->count());

        // Planificateur relancé et job rejoué (reprise après incident) : aucune nouvelle publication.
        $this->assertSame(0, app(PublishingService::class)->dispatchDue());
        $jobs->first()->handle(app(PublishingService::class));
        $this->assertCount(5, $this->uploadTokens);
        $this->assertSame(5, PublicationAttempt::query()->count());
    }

    public function test_a_disconnected_destination_is_named_and_another_users_channel_is_refused(): void
    {
        // Compte d'un autre utilisateur parmi les destinations : refusé, rien n'est créé.
        $foreign = $this->channel(User::factory()->create(), 'UC_foreign', 'token-foreign', 'Chaîne étrangère');
        $this->save([...$this->channels, $foreign])->assertUnprocessable()->assertJsonValidationErrors('targets.5.social_account_id');
        $this->assertSame(0, Post::query()->count());

        // Compte déconnecté après l'enregistrement : publication immédiate refusée en le nommant.
        $postId = $this->save($this->channels)->json('data.id');
        $this->channels[3]->forceFill(['status' => 'expired'])->save();
        $message = $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/publish")
            ->assertUnprocessable()->json('errors.targets.0');
        $this->assertStringContainsString('Chaîne 4', $message);
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_mixed_networks_each_destination_follows_its_own_platform_rules(): void
    {
        $linkedinA = $this->linkedinAccount($this->user, attributes: ['external_account_id' => 'li-a', 'display_name' => 'Pro A']);
        $linkedinB = $this->linkedinAccount($this->user, attributes: ['external_account_id' => 'li-b', 'display_name' => 'Pro B']);
        $response = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft', 'body' => 'Annonce', 'media_ids' => [$this->video->id],
            'targets' => [
                ['social_account_id' => $this->channels[0]->id, 'format' => 'video', 'options' => ['title' => 'Vidéo']],
                ['social_account_id' => $this->channels[1]->id, 'format' => 'video', 'options' => ['title' => 'Vidéo']],
                ['social_account_id' => $linkedinA->id, 'format' => 'feed'],
                ['social_account_id' => $linkedinB->id, 'format' => 'feed'],
            ],
        ])->assertCreated();
        $postId = $response->json('data.id');

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$postId}/publish")->assertOk();
        Queue::assertPushed(PublishPostVariantJob::class, 4);
        $this->runJobs();

        // Vidéo : publiée sur les deux chaînes ; LinkedIn (texte seul) : refus explicite par compte, sans appel.
        $this->assertSame(VariantStatus::Published, $this->variantOf($postId, $this->channels[0])->status);
        $this->assertSame(VariantStatus::Published, $this->variantOf($postId, $this->channels[1])->status);
        foreach ([$linkedinA, $linkedinB] as $account) {
            $this->assertSame('MEDIA_NOT_SUPPORTED', $this->variantOf($postId, $account)->last_error_code);
        }
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'linkedin'));
        $this->assertSame('partially_published', Post::findOrFail($postId)->status->value);
    }
}
