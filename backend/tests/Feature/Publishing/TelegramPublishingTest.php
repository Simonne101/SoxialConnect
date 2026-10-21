<?php

namespace Tests\Feature\Publishing;

use App\Domain\Content\Enums\VariantStatus;
use App\Domain\Publishing\Adapters\TelegramPublisher;
use App\Domain\Publishing\Data\PublishRequest;
use App\Domain\Publishing\PublishingService;
use App\Domain\Social\Enums\SocialAccountStatus;
use App\Jobs\PublishPostVariantJob;
use App\Models\Post;
use App\Models\PostVariant;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Social\Concerns\TelegramFixtures;
use Tests\TestCase;

/**
 * Telegram dans le moteur de publication : PublisherRegistry → TelegramPublisher → (faux) serveur
 * Bot API → PublishResult → PublishingService. Aucun appel réel à Telegram.
 */
class TelegramPublishingTest extends TestCase
{
    use RefreshDatabase, TelegramFixtures;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('media');
        Queue::fake();
        $this->user = User::factory()->create();
        $this->account = $this->telegramAccount($this->user);
    }

    /** @param list<string> $mediaIds */
    private function createPost(string $body, array $mediaIds = [], array $options = []): Post
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft',
            'body' => $body,
            'media_ids' => $mediaIds,
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed', 'options' => $options]],
        ])->assertCreated()->json('data.id');

        return Post::findOrFail($id);
    }

    private function publishAndRun(Post $post): PostVariant
    {
        app(PublishingService::class)->publishNow($this->user, $post);
        Queue::pushed(PublishPostVariantJob::class)->values()[0]->handle(app(PublishingService::class));

        return $post->variants()->firstOrFail()->fresh();
    }

    private function assertNothingSent(): void
    {
        foreach (['sendMessage', 'sendPhoto', 'sendVideo', 'sendAnimation', 'sendMediaGroup'] as $method) {
            $this->assertSame([], $this->telegramCalls($method), "Aucun appel {$method} attendu.");
        }
    }

    // --- Succès -----------------------------------------------------------------------------

    public function test_text_post_is_sent_as_plain_text_and_published_with_the_message_id(): void
    {
        $this->fakeTelegram();
        $variant = $this->publishAndRun($this->createPost('Bonjour <b>Telegram</b> *gras*', options: ['hashtags' => ['lancement', 'soxial']]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('101', $variant->external_post_id);
        $this->assertSame('https://t.me/machaine/101', $variant->external_url);
        $sent = $this->telegramCalls('sendMessage')[0];
        $this->assertSame(self::CHANNEL_ID, (string) $sent['chat_id']);
        $this->assertSame("Bonjour <b>Telegram</b> *gras*\n\n#lancement #soxial", $sent['text']);
        $this->assertArrayNotHasKey('parse_mode', $sent, 'Texte brut : aucune interprétation de balises.');

        $attempt = PublicationAttempt::sole();
        $this->assertSame('succeeded', $attempt->status->value);
        $this->assertSame([101], $attempt->response_excerpt['message_ids']);
    }

    public function test_private_channel_gets_a_t_me_c_link_and_basic_group_no_link(): void
    {
        $this->account->update(['username' => null]);
        $this->fakeTelegram();
        $this->assertSame('https://t.me/c/1234567890/101', $this->publishAndRun($this->createPost('Privé'))->external_url);

        $this->account->forceFill(['external_account_id' => '-4242'])->save();
        Queue::fake();
        $this->assertNull($this->publishAndRun($this->createPost('Groupe simple'))->external_url);
    }

    public function test_single_image_is_uploaded_from_private_storage_with_a_caption(): void
    {
        $this->fakeTelegram();
        $image = $this->telegramMedia($this->user, 'image/jpeg', 4096, 'i');
        $variant = $this->publishAndRun($this->createPost('Légende de la photo', [$image->id]));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('201', $variant->external_post_id);
        $call = $this->telegramCalls('sendPhoto')[0];
        $this->assertSame(self::CHANNEL_ID, $call['chat_id']);
        $this->assertSame('Légende de la photo', $call['caption']);
        $this->assertSame(str_repeat('i', 4096), $call['photo']['contents']);
        $this->assertSame('media.jpg', $call['photo']['filename'], 'Le nom d\'origine du fichier n\'est pas diffusé.');
    }

    public function test_gif_is_sent_as_an_animation(): void
    {
        $this->fakeTelegram();
        $gif = $this->telegramMedia($this->user, 'image/gif');
        $this->assertSame('202', $this->publishAndRun($this->createPost('', [$gif->id]))->external_post_id);
        $this->assertArrayNotHasKey('caption', $this->telegramCalls('sendAnimation')[0]);
    }

    public function test_mp4_video_is_sent_with_streaming(): void
    {
        $this->fakeTelegram();
        $video = $this->telegramMedia($this->user, 'video/mp4', 8192, 'v');
        $variant = $this->publishAndRun($this->createPost('Ma vidéo', [$video->id]));

        $this->assertSame('203', $variant->external_post_id);
        $call = $this->telegramCalls('sendVideo')[0];
        $this->assertSame('true', $call['supports_streaming']);
        $this->assertSame(str_repeat('v', 8192), $call['video']['contents']);
    }

    public function test_album_mixes_photos_and_videos_with_the_caption_on_the_first_item(): void
    {
        $this->fakeTelegram();
        $media = [
            $this->telegramMedia($this->user, 'image/jpeg', 100, 'a'),
            $this->telegramMedia($this->user, 'video/mp4', 200, 'b'),
            $this->telegramMedia($this->user, 'image/png', 300, 'c'),
        ];
        $variant = $this->publishAndRun($this->createPost('Album', array_map(fn ($m) => $m->id, $media)));

        $this->assertSame(VariantStatus::Published, $variant->status);
        $this->assertSame('300', $variant->external_post_id, 'Identifiant du premier message de l\'album.');
        $call = $this->telegramCalls('sendMediaGroup')[0];
        $this->assertSame([
            ['type' => 'photo', 'media' => 'attach://file0', 'caption' => 'Album'],
            ['type' => 'video', 'media' => 'attach://file1', 'supports_streaming' => true],
            ['type' => 'photo', 'media' => 'attach://file2'],
        ], json_decode($call['media'], true));
        $this->assertSame(str_repeat('b', 200), $call['file1']['contents']);
        $this->assertSame([300, 301, 302], PublicationAttempt::sole()->response_excerpt['message_ids']);
    }

    // --- Limites (refus avant tout envoi) -------------------------------------------------------

    public function test_text_over_4096_characters_is_refused_without_sending(): void
    {
        $this->fakeTelegram();
        $variant = $this->publishAndRun($this->createPost(str_repeat('é', 4097)));

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('TEXT_TOO_LONG', $variant->last_error_code);
        $this->assertNothingSent();
    }

    public function test_text_of_exactly_4096_characters_is_accepted(): void
    {
        $this->fakeTelegram();
        $this->assertSame(VariantStatus::Published, $this->publishAndRun($this->createPost(str_repeat('a', 4096)))->status);
    }

    public function test_caption_over_1024_characters_with_a_media_is_refused(): void
    {
        $this->fakeTelegram();
        $image = $this->telegramMedia($this->user, 'image/jpeg');
        $variant = $this->publishAndRun($this->createPost(str_repeat('a', 1025), [$image->id]));

        $this->assertSame('TEXT_TOO_LONG', $variant->last_error_code);
        $this->assertStringContainsString('1024', $variant->last_error_message);
        $this->assertNothingSent();
    }

    public function test_emoji_count_as_two_utf16_units_like_telegram(): void
    {
        $this->assertSame(2, TelegramPublisher::length('😀'));
        $this->assertSame(1, TelegramPublisher::length('é'));
    }

    public function test_more_than_10_media_are_refused(): void
    {
        $this->fakeTelegram();
        $ids = array_map(fn () => $this->telegramMedia($this->user, 'image/jpeg', 10)->id, range(1, 11));
        $variant = $this->publishAndRun($this->createPost('Trop', $ids));

        $this->assertSame('TOO_MANY_MEDIA', $variant->last_error_code);
        $this->assertNothingSent();
    }

    public function test_non_mp4_video_document_and_gif_in_album_are_refused(): void
    {
        $this->fakeTelegram();
        foreach ([
            [['video/quicktime'], 'MP4'],
            [['application/pdf'], 'images'],
            [['image/gif', 'image/jpeg'], 'GIF'],
        ] as [$mimes, $expected]) {
            Queue::fake();
            $ids = array_map(fn ($mime) => $this->telegramMedia($this->user, $mime)->id, $mimes);
            $variant = $this->publishAndRun($this->createPost('Média', $ids));
            $this->assertSame('MEDIA_INCOMPATIBLE', $variant->last_error_code);
            $this->assertStringContainsString($expected, $variant->last_error_message);
        }
        $this->assertNothingSent();
    }

    public function test_files_over_the_bot_api_limits_are_refused(): void
    {
        config(['services.telegram.max_upload_mb' => 1, 'services.telegram.max_photo_mb' => 1]);
        $this->fakeTelegram();
        $video = $this->telegramMedia($this->user, 'video/mp4', 1024 * 1024 + 1);
        $this->assertSame('FILE_TOO_LARGE', $this->publishAndRun($this->createPost('Grosse vidéo', [$video->id]))->last_error_code);

        Queue::fake();
        config(['services.telegram.max_upload_mb' => 50]);
        $photo = $this->telegramMedia($this->user, 'image/jpeg', 1024 * 1024 + 1);
        $variant = $this->publishAndRun($this->createPost('Grosse photo', [$photo->id]));
        $this->assertSame('FILE_TOO_LARGE', $variant->last_error_code);
        $this->assertStringContainsString('10 Mo', $variant->last_error_message);
        $this->assertNothingSent();
    }

    // --- Erreurs Telegram -----------------------------------------------------------------------

    public function test_rate_limit_is_a_temporary_failure_with_an_automatic_retry(): void
    {
        $this->fakeTelegram(['sendMessage' => fn () => self::tgError(429, 'Too Many Requests: retry after 7', ['retry_after' => 7])]);
        $variant = $this->publishAndRun($this->createPost('Limite'));

        $this->assertSame(VariantStatus::Queued, $variant->status, 'Nouvel essai automatique programmé.');
        $attempt = PublicationAttempt::sole();
        $this->assertSame('RATE_LIMITED', $attempt->error_code);
        $this->assertTrue($attempt->retryable);
        $this->assertSame(7, $attempt->response_excerpt['retry_after']);
        Queue::assertPushed(PublishPostVariantJob::class, 2);
    }

    public function test_bot_removed_is_permanent_and_flags_the_destination_for_reconnection(): void
    {
        $this->fakeTelegram(['sendMessage' => fn () => self::tgError(403, 'Forbidden: bot was kicked from the channel chat')]);
        $variant = $this->publishAndRun($this->createPost('Bot retiré'));

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('PERMISSION_DENIED', $variant->last_error_code);
        $this->assertFalse(PublicationAttempt::sole()->retryable);
        $account = $this->account->fresh();
        $this->assertSame(SocialAccountStatus::Error, $account->status);
        $this->assertStringContainsString('reconnectez', $account->status_reason);
    }

    public function test_missing_rights_and_unknown_chat_are_permanent(): void
    {
        foreach (['Bad Request: not enough rights to send text messages to the chat', 'Bad Request: chat not found'] as $description) {
            Queue::fake();
            $this->account->refresh()->forceFill(['status' => SocialAccountStatus::Connected])->save();
            $this->fakeTelegram(['sendMessage' => fn () => self::tgError(400, $description)]);
            $variant = $this->publishAndRun($this->createPost('Droits'));
            $this->assertSame('PERMISSION_DENIED', $variant->last_error_code, $description);
        }
    }

    public function test_other_bad_requests_are_classified(): void
    {
        $image = $this->telegramMedia($this->user, 'image/jpeg');
        foreach ([
            [400, 'Bad Request: message is too long', [], 'TEXT_TOO_LONG'],
            [413, 'Request Entity Too Large', [$image->id], 'FILE_TOO_LARGE'],
            [400, 'Bad Request: PHOTO_INVALID_DIMENSIONS', [$image->id], 'MEDIA_REJECTED'],
            [401, 'Unauthorized', [], 'BOT_TOKEN_INVALID'],
            [400, 'Bad Request: something else', [], 'INVALID_REQUEST'],
        ] as [$code, $description, $media, $expected]) {
            Queue::fake();
            $fail = fn () => self::tgError($code, $description);
            $this->fakeTelegram(['sendMessage' => $fail, 'sendPhoto' => $fail]);
            $variant = $this->publishAndRun($this->createPost('Erreur', $media));
            $this->assertSame(VariantStatus::Failed, $variant->status, $description);
            $this->assertSame($expected, $variant->last_error_code, $description);
        }
    }

    public function test_server_error_and_timeout_during_sending_are_unknown_never_retried(): void
    {
        foreach ([
            fn () => self::tgError(502, 'Bad Gateway'),
            self::connectionFailure(28),
        ] as $failure) {
            Queue::fake();
            $this->fakeTelegram(['sendMessage' => $failure]);
            $variant = $this->publishAndRun($this->createPost('Inconnu'));

            $this->assertSame(VariantStatus::Failed, $variant->status);
            $this->assertSame(PublishingService::UNKNOWN_CODE, $variant->last_error_code);
            Queue::assertPushed(PublishPostVariantJob::class, 1);
        }
        $this->assertSame(2, PublicationAttempt::query()->where('status', 'unknown')->count());
    }

    public function test_connection_failure_before_sending_is_temporary(): void
    {
        $this->fakeTelegram(['sendMessage' => self::connectionFailure(7)]);
        $variant = $this->publishAndRun($this->createPost('Réseau'));

        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertSame('NETWORK_ERROR', PublicationAttempt::sole()->error_code);
    }

    public function test_group_migrated_to_supergroup_updates_the_chat_id_and_retries(): void
    {
        $this->account->forceFill(['external_account_id' => '-4242', 'account_type' => 'group'])->save();
        $this->fakeTelegram(['sendMessage' => fn () => self::tgError(400, 'Bad Request: group chat was upgraded to a supergroup chat', ['migrate_to_chat_id' => -1009876543210])]);
        $variant = $this->publishAndRun($this->createPost('Migration'));

        $this->assertSame(VariantStatus::Queued, $variant->status);
        $this->assertSame('CHAT_MIGRATED', PublicationAttempt::sole()->error_code);
        $this->assertSame('-1009876543210', $this->account->fresh()->external_account_id);
    }

    public function test_not_configured_bot_sends_nothing(): void
    {
        $this->fakeTelegram();
        config(['services.telegram.bot_token' => null]);
        $variant = $this->publishAndRun($this->createPost('Sans bot'));

        $this->assertSame(VariantStatus::Failed, $variant->status);
        $this->assertSame('NOT_CONFIGURED', $variant->last_error_code);
        $this->assertSame([], $this->telegramCalls);
    }

    public function test_the_bot_token_is_never_stored_with_the_attempts(): void
    {
        $this->fakeTelegram(['sendMessage' => self::connectionFailure(7)]);
        $this->publishAndRun($this->createPost('Secret'));
        $this->fakeTelegram(['sendMessage' => fn () => self::tgError(400, 'Bad Request: something else')]);
        Queue::fake();
        $this->publishAndRun($this->createPost('Secret 2'));

        foreach (['publication_attempts', 'post_variants', 'audit_logs', 'social_accounts'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString(self::BOT_TOKEN, $dump, $table);
            $this->assertStringNotContainsString('TEST-bot-token', $dump, $table);
        }
    }

    public function test_publisher_text_appends_only_missing_hashtags(): void
    {
        $publisher = app(TelegramPublisher::class);
        $request = new PublishRequest('p', 'v', 'a', 'telegram', self::CHANNEL_ID, 'feed', 'Déjà #promo ici', ['hashtags' => ['promo', '#nouveau']], [], 'k', 1);

        $this->assertSame("Déjà #promo ici\n\n#nouveau", $publisher->text($request));
    }
}
