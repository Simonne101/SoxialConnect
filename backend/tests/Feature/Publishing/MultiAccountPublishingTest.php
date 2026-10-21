<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\PublishingService;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenKind;
use App\Jobs\PublishPostVariantJob;
use App\Models\Media;
use App\Models\Post;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Social\Concerns\TelegramFixtures;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Plusieurs comptes d'un même réseau : la publication part TOUJOURS vers le compte choisi
 * (targets[].social_account_id), jamais vers « le » compte du réseau. Faux serveurs YouTube et
 * Telegram : chaque appel enregistre le jeton (YouTube) ou la conversation (Telegram) utilisés.
 */
class MultiAccountPublishingTest extends TestCase
{
    use RefreshDatabase, TelegramFixtures, YouTubeFixtures;

    private User $user;

    private Media $video;

    /** @var list<string> jetons YouTube présentés */
    private array $youtubeTokens = [];

    /** @var list<string> conversations Telegram visées */
    private array $telegramChats = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->user = User::factory()->create();
        $this->configureYouTube();
        $this->configureTelegram();
        $this->video = $this->videoMedia($this->user);

        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), 'https://api.telegram.org/')) {
                // JSON (texte) ou multipart (fichiers) : le chat_id est lu dans les deux formats.
                $data = $request->data();
                $chatId = $request->isMultipart() ? collect($data)->firstWhere('name', 'chat_id')['contents'] ?? null : ($data['chat_id'] ?? null);
                $this->telegramChats[] = (string) $chatId;

                return self::tgOk(['message_id' => 500 + count($this->telegramChats)]);
            }
            if ($request->method() === 'POST' && str_contains($request->url(), 'upload/youtube/v3/videos')) {
                $this->youtubeTokens[] = Str::after($request->header('Authorization')[0] ?? '', 'Bearer ');

                return Http::response('', 200, ['Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=S'.count($this->youtubeTokens)]);
            }
            if ($request->method() === 'PUT' && str_contains($request->url(), 'upload_id=')) {
                return Http::response(['kind' => 'youtube#video', 'id' => 'vid-'.count($this->youtubeTokens), 'status' => ['uploadStatus' => 'uploaded', 'privacyStatus' => 'private']]);
            }

            return null;
        });
    }

    private function youtubeChannel(string $channelId, string $token, ?User $owner = null): SocialAccount
    {
        $account = SocialAccount::factory()->for($owner ?? $this->user)->create([
            'social_platform_id' => $this->configureYouTube()->id,
            'external_account_id' => $channelId,
            'account_type' => 'channel',
            'display_name' => "Chaîne {$channelId}",
        ]);
        SocialToken::create([
            'social_account_id' => $account->id,
            'token_kind' => TokenKind::User,
            'access_token_encrypted' => $token,
            'refresh_token_encrypted' => "refresh-{$token}",
            'expires_at' => now()->addHour(),
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
            'status' => 'active',
        ]);

        return $account;
    }

    private function telegramChannel(string $chatId, ?User $owner = null): SocialAccount
    {
        return $this->telegramAccount($owner ?? $this->user, ['external_account_id' => $chatId, 'display_name' => "Canal {$chatId}", 'username' => null]);
    }

    /** @param list<array{0: SocialAccount, 1: string}> $targets [compte, format] */
    private function savePost(array $targets, array $overrides = [])
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'title' => 'Multi-comptes',
            'body' => 'Texte de test',
            'media_ids' => [$this->video->id],
            'targets' => array_map(fn ($t) => ['social_account_id' => $t[0]->id, 'format' => $t[1]], $targets),
        ], $overrides));
    }

    private function publishAll(Post $post): void
    {
        app(PublishingService::class)->publishNow($this->user, $post);
        foreach (Queue::pushed(PublishPostVariantJob::class) as $job) {
            $job->handle(app(PublishingService::class));
        }
    }

    // --- YouTube ------------------------------------------------------------------------------

    public function test_youtube_single_account_still_publishes(): void
    {
        $only = $this->youtubeChannel('UC_only', 'token-only');
        $this->publishAll(Post::findOrFail($this->savePost([[$only, 'video']])->assertCreated()->json('data.id')));

        $this->assertSame(['token-only'], $this->youtubeTokens);
        $this->assertSame(VariantStatus::Published, Post::sole()->variants()->sole()->status);
    }

    public function test_youtube_two_channels_publish_to_the_selected_one(): void
    {
        $a = $this->youtubeChannel('UC_A', 'token-A');
        $b = $this->youtubeChannel('UC_B', 'token-B');

        foreach ([[$b, 'token-B'], [$a, 'token-A']] as [$selected, $expectedToken]) {
            Queue::fake();
            $this->youtubeTokens = [];
            $id = $this->savePost([[$selected, 'video']])->assertCreated()
                ->assertJsonPath('data.variants.0.social_account_id', $selected->id)->json('data.id');
            $this->publishAll(Post::findOrFail($id));

            $this->assertSame([$expectedToken], $this->youtubeTokens, 'Seul le jeton du compte choisi est utilisé.');
            $variantId = Post::findOrFail($id)->variants()->sole()->id;
            $this->assertSame($selected->id, PublicationAttempt::query()->where('post_variant_id', $variantId)->sole()->social_account_id);
        }
    }

    // --- Telegram -----------------------------------------------------------------------------

    public function test_telegram_single_destination_still_publishes(): void
    {
        $only = $this->telegramChannel('-1001111111111');
        $this->publishAll(Post::findOrFail($this->savePost([[$only, 'feed']], ['media_ids' => []])->json('data.id')));

        $this->assertSame(['-1001111111111'], $this->telegramChats);
    }

    public function test_telegram_two_destinations_publish_to_the_selected_one(): void
    {
        $a = $this->telegramChannel('-1001111111111');
        $b = $this->telegramChannel('-1002222222222');

        $this->publishAll(Post::findOrFail($this->savePost([[$b, 'feed']], ['media_ids' => []])->json('data.id')));
        $this->assertSame(['-1002222222222'], $this->telegramChats);

        Queue::fake();
        $this->publishAll(Post::findOrFail($this->savePost([[$a, 'feed']], ['media_ids' => []])->json('data.id')));
        $this->assertSame(['-1002222222222', '-1001111111111'], $this->telegramChats);
    }

    // --- Plusieurs réseaux, programmation, édition --------------------------------------------

    public function test_one_post_targets_a_specific_account_on_each_network(): void
    {
        $this->youtubeChannel('UC_A', 'token-A');
        $youtubeB = $this->youtubeChannel('UC_B', 'token-B');
        $telegramA = $this->telegramChannel('-1001111111111');
        $this->telegramChannel('-1002222222222');

        $id = $this->savePost([[$youtubeB, 'video'], [$telegramA, 'feed']])->assertCreated()->json('data.id');
        $this->publishAll(Post::findOrFail($id));

        $this->assertSame(['token-B'], $this->youtubeTokens);
        $this->assertSame(['-1001111111111'], $this->telegramChats);
        $this->assertSame('published', Post::findOrFail($id)->status->value);
        $this->assertEqualsCanonicalizing([$youtubeB->id, $telegramA->id], PublicationAttempt::pluck('social_account_id')->all());
    }

    public function test_scheduled_post_publishes_to_the_selected_account(): void
    {
        $this->telegramChannel('-1001111111111');
        $b = $this->telegramChannel('-1002222222222');

        $this->savePost([[$b, 'feed']], ['mode' => 'schedule', 'media_ids' => [], 'scheduled_at' => now()->addMinutes(10)->toIso8601String()])->assertCreated();
        $this->travel(11)->minutes();
        $this->assertSame(1, app(PublishingService::class)->dispatchDue());
        foreach (Queue::pushed(PublishPostVariantJob::class) as $job) {
            $job->handle(app(PublishingService::class));
        }

        $this->assertSame(['-1002222222222'], $this->telegramChats);
    }

    public function test_switching_the_account_of_a_draft_replaces_the_target(): void
    {
        $a = $this->telegramChannel('-1001111111111');
        $b = $this->telegramChannel('-1002222222222');
        $post = $this->savePost([[$a, 'feed']], ['media_ids' => []])->json('data');

        $this->actingAs($this->user)->fromSpa()->putJson("/api/v1/content/posts/{$post['id']}", [
            'mode' => 'draft', 'body' => 'Texte de test', 'media_ids' => [], 'lock_version' => $post['lock_version'],
            'targets' => [['social_account_id' => $b->id, 'format' => 'feed']],
        ])->assertOk()->assertJsonCount(1, 'data.variants')->assertJsonPath('data.variants.0.social_account_id', $b->id);

        $this->publishAll(Post::findOrFail($post['id']));
        $this->assertSame(['-1002222222222'], $this->telegramChats);
    }

    public function test_attempt_history_keeps_the_account_used(): void
    {
        $b = $this->telegramChannel('-1002222222222');
        $id = $this->savePost([[$b, 'feed']], ['media_ids' => []])->json('data.id');
        $this->publishAll(Post::findOrFail($id));

        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$id}/attempts")->assertOk()
            ->assertJsonPath('data.0.status', 'succeeded');
        $this->assertSame($b->id, PublicationAttempt::sole()->social_account_id);
    }

    // --- Sécurité -----------------------------------------------------------------------------

    public function test_another_users_account_is_refused(): void
    {
        $foreign = $this->telegramChannel('-1009999999999', User::factory()->create());

        foreach (['draft', 'schedule'] as $mode) {
            $this->savePost([[$foreign, 'feed']], ['mode' => $mode, 'media_ids' => [], 'scheduled_at' => now()->addHour()->toIso8601String()])
                ->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);
        }
        $this->assertSame(0, Post::count());
        $this->assertSame([], $this->telegramChats);
    }

    public function test_unknown_and_deleted_accounts_are_refused(): void
    {
        $deleted = $this->telegramChannel('-1003333333333');
        $deleted->delete();

        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft', 'body' => 'x', 'targets' => [['social_account_id' => (string) Str::uuid(), 'format' => 'feed']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);
        $this->savePost([[$deleted, 'feed']], ['media_ids' => []])->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);
    }

    public function test_disconnected_account_cannot_be_scheduled_or_published(): void
    {
        $account = $this->telegramChannel('-1004444444444');
        $id = $this->savePost([[$account, 'feed']], ['media_ids' => []])->assertCreated()->json('data');
        $account->forceFill(['status' => SocialAccountStatus::Revoked])->save();

        // Programmer : refusé.
        $this->savePost([[$account, 'feed']], ['mode' => 'schedule', 'media_ids' => [], 'scheduled_at' => now()->addHour()->toIso8601String()])
            ->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);
        // Publier le brouillon existant (compte déconnecté entre-temps) : refusé, rien n'est envoyé.
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$id['id']}/publish", ['lock_version' => $id['lock_version']])
            ->assertUnprocessable()->assertJsonValidationErrors(['targets']);
        $this->assertSame([], $this->telegramChats);
        $this->assertSame(0, PublicationAttempt::count());
    }
}
