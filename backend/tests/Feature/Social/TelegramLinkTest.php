<?php

namespace Tests\Feature\Social;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Social\Concerns\TelegramFixtures;
use Tests\TestCase;

/**
 * Liaison d'une chaîne ou d'un groupe Telegram (lien profond + bouton officiel + webhook),
 * sécurité du webhook, suivi des droits du bot, synchronisation et déconnexion.
 * Aucun appel réel : faux serveur Bot API (TelegramFixtures).
 */
class TelegramLinkTest extends TestCase
{
    use RefreshDatabase, TelegramFixtures;

    private const TG_USER = 5550001;

    private User $user;

    private int $updateId = 1000;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureTelegram();
        $this->fakeTelegram();
        $this->user = User::factory()->create();
    }

    private function webhook(array $update, ?string $secret = self::WEBHOOK_SECRET): TestResponse
    {
        $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

        return $this->postJson('/api/v1/webhooks/telegram', ['update_id' => ++$this->updateId] + $update, $headers);
    }

    private function privateMessage(array $fields, int $from = self::TG_USER): array
    {
        return ['message' => array_merge([
            'message_id' => $this->updateId,
            'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Ada'],
            'chat' => ['id' => $from, 'type' => 'private'],
            'date' => time(),
        ], $fields)];
    }

    private function linkCode(): string
    {
        $url = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/telegram/link')->assertOk()->json('data.link_url');
        $this->assertMatchesRegularExpression('#^https://t\.me/SoxialTestBot\?start=[A-Za-z0-9]{48}$#', $url);

        return substr($url, strpos($url, '=') + 1);
    }

    private function startAndShare(int $requestId = 1, string $chatId = self::CHANNEL_ID, int $from = self::TG_USER): void
    {
        $this->webhook($this->privateMessage(['text' => '/start '.$this->linkCode()], $from))->assertOk();
        $this->webhook($this->privateMessage(['chat_shared' => ['request_id' => $requestId, 'chat_id' => (int) $chatId]], $from))->assertOk();
    }

    /** @return list<string> textes envoyés en conversation privée */
    private function replies(): array
    {
        return array_map(fn ($c) => $c['text'], $this->telegramCalls('sendMessage'));
    }

    // --- Lien de liaison ---------------------------------------------------------------------

    public function test_link_requires_a_verified_session_and_a_configured_bot(): void
    {
        $this->fromSpa()->postJson('/api/v1/social/telegram/link')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->fromSpa()->postJson('/api/v1/social/telegram/link')->assertForbidden();

        config(['services.telegram.webhook_secret' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/telegram/link')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion Telegram n\'est pas encore configurée sur le serveur.');
    }

    public function test_link_returns_a_t_me_deep_link_and_never_the_token(): void
    {
        $response = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/telegram/link')->assertOk()
            ->assertJsonPath('data.bot_username', 'SoxialTestBot')
            ->assertJsonStructure(['data' => ['link_url', 'bot_username', 'expires_at']]);

        $this->assertStringNotContainsString('TEST-bot-token', $response->getContent());
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $response->getContent());
    }

    // --- Sécurité du webhook -------------------------------------------------------------------

    public function test_webhook_rejects_missing_or_wrong_secret_and_unconfigured_secret(): void
    {
        $this->webhook($this->privateMessage(['text' => '/start']), null)->assertForbidden();
        $this->webhook($this->privateMessage(['text' => '/start']), 'mauvais-secret')->assertForbidden();
        config(['services.telegram.webhook_secret' => null]);
        $this->webhook($this->privateMessage(['text' => '/start']), '')->assertForbidden();

        $this->assertSame(0, WebhookEvent::count());
        $this->assertSame([], $this->telegramCalls);
    }

    public function test_webhook_requires_an_update_id_and_ignores_unrelated_updates(): void
    {
        $this->postJson('/api/v1/webhooks/telegram', ['message' => []], ['X-Telegram-Bot-Api-Secret-Token' => self::WEBHOOK_SECRET])->assertStatus(400);

        $this->webhook(['channel_post' => ['text' => 'contenu de la chaîne']])->assertOk()->assertJson(['ok' => true]);
        $event = WebhookEvent::sole();
        $this->assertSame('ignored', $event->status->value);
        $this->assertNull($event->payload, 'Aucun contenu de conversation conservé.');
    }

    public function test_a_redelivered_update_is_processed_only_once(): void
    {
        $update = ['update_id' => 777] + $this->privateMessage(['text' => '/start']);
        $headers = ['X-Telegram-Bot-Api-Secret-Token' => self::WEBHOOK_SECRET];
        $this->postJson('/api/v1/webhooks/telegram', $update, $headers)->assertOk();
        $this->postJson('/api/v1/webhooks/telegram', $update, $headers)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame('processed', WebhookEvent::sole()->status->value);
        $this->assertCount(1, $this->telegramCalls('sendMessage'));
    }

    // --- /start ----------------------------------------------------------------------------------

    public function test_start_with_a_valid_code_offers_the_official_chat_picker_buttons(): void
    {
        $this->webhook($this->privateMessage(['text' => '/start '.$this->linkCode()]))->assertOk();

        $reply = $this->telegramCalls('sendMessage')[0];
        $this->assertSame(self::TG_USER, $reply['chat_id']);
        $this->assertCount(1, $this->telegramCalls('sendMessage'), 'Aucun message de secours quand le choix est affiché.');

        // Contrat ChatAdministratorRights : tous les champs obligatoires, jamais tous à false
        // (sinon Telegram répond « ADMIN_RIGHTS_EMPTY »), aucun droit de modération ou de gestion.
        $rights = [
            'is_anonymous' => false, 'can_manage_chat' => true, 'can_delete_messages' => false,
            'can_manage_video_chats' => false, 'can_restrict_members' => false, 'can_promote_members' => false,
            'can_change_info' => false, 'can_invite_users' => false, 'can_post_stories' => false,
            'can_edit_stories' => false, 'can_delete_stories' => false, 'can_send_welcome_messages' => false,
        ];
        $channelRights = $rights + ['can_post_messages' => true];
        $this->assertSame([
            'keyboard' => [
                [['text' => 'Choisir une chaîne', 'request_chat' => ['request_id' => 1, 'chat_is_channel' => true, 'user_administrator_rights' => $channelRights, 'bot_administrator_rights' => $channelRights]]],
                [['text' => 'Choisir un groupe', 'request_chat' => ['request_id' => 2, 'chat_is_channel' => false, 'user_administrator_rights' => $rights, 'bot_administrator_rights' => $rights]]],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ], $reply['reply_markup']);
    }

    public function test_a_refused_chat_picker_is_logged_with_its_reason_and_answered_with_a_fallback(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = ['level' => $e->level, 'message' => $e->message, 'context' => $e->context];
        });
        $this->fakeTelegram(['sendMessage' => fn (array $d) => isset($d['reply_markup']['keyboard'])
            ? self::tgError(400, 'Bad Request: ADMIN_RIGHTS_EMPTY')
            : self::tgOk(['message_id' => 102])]);
        $code = $this->linkCode();

        $this->webhook($this->privateMessage(['text' => '/start '.$code]))->assertOk();

        // Motif exact journalisé, avec l'opération concernée.
        $failure = collect($logs)->firstWhere('message', 'telegram.reply_failed');
        $this->assertNotNull($failure, 'Le refus de Telegram doit être journalisé.');
        $this->assertSame('warning', $failure['level']);
        $this->assertSame(['operation' => 'start.chat_picker', 'http_status' => 400, 'error_code' => 400, 'description' => 'Bad Request: ADMIN_RIGHTS_EMPTY'], $failure['context']);

        // L'utilisateur reçoit un message de secours, sans clavier de choix.
        $calls = $this->telegramCalls('sendMessage');
        $this->assertCount(2, $calls);
        $this->assertStringContainsString('Une erreur empêche d\'afficher le choix', $calls[1]['text']);
        $this->assertTrue($calls[1]['reply_markup']['remove_keyboard']);

        // Aucune session de liaison laissée ouverte ; le code reste à usage unique.
        $this->webhook($this->privateMessage(['chat_shared' => ['request_id' => 1, 'chat_id' => (int) self::CHANNEL_ID]]))->assertOk();
        $this->assertSame(0, SocialAccount::count());
        $this->assertStringContainsString('Cette demande a expiré', $this->replies()[2]);
        $this->webhook($this->privateMessage(['text' => '/start '.$code]))->assertOk();
        $this->assertStringContainsString('expiré ou a déjà été utilisé', $this->replies()[3]);

        // Jamais de token, de secret ni de code de liaison dans les journaux.
        $dump = json_encode($logs);
        $this->assertStringNotContainsString(self::BOT_TOKEN, $dump);
        $this->assertStringNotContainsString('TEST-bot-token', $dump);
        $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $dump);
        $this->assertStringNotContainsString($code, $dump);
    }

    public function test_a_telegram_description_containing_secrets_is_masked_in_logs(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = $e->context;
        });
        $code = $this->linkCode();
        $this->fakeTelegram(['sendMessage' => fn () => self::tgError(400, 'Bad Request: '.self::BOT_TOKEN.' '.self::WEBHOOK_SECRET.' '.$code)]);

        $this->webhook($this->privateMessage(['text' => '/start '.$code]))->assertOk();

        $dump = json_encode($logs);
        $this->assertStringContainsString('[MASQUÉ]', json_encode($logs, JSON_UNESCAPED_UNICODE));
        foreach ([self::BOT_TOKEN, 'TEST-bot-token', self::WEBHOOK_SECRET, $code] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_a_code_is_single_use_and_unknown_codes_are_refused(): void
    {
        $code = $this->linkCode();
        $this->webhook($this->privateMessage(['text' => '/start '.$code]))->assertOk();
        $this->webhook($this->privateMessage(['text' => '/start '.$code], 9990001))->assertOk();
        $this->webhook($this->privateMessage(['text' => '/start '.str_repeat('x', 48)], 9990002))->assertOk();

        $replies = $this->replies();
        $this->assertStringContainsString('expiré ou a déjà été utilisé', $replies[1]);
        $this->assertStringContainsString('expiré ou a déjà été utilisé', $replies[2]);
    }

    public function test_start_without_code_explains_how_to_connect(): void
    {
        $this->webhook($this->privateMessage(['text' => '/start']))->assertOk();
        $this->assertStringContainsString('Réseaux sociaux › Telegram › Connecter', $this->replies()[0]);
    }

    // --- Liaison --------------------------------------------------------------------------------

    public function test_full_flow_links_a_channel_to_the_user(): void
    {
        $this->startAndShare();

        $account = SocialAccount::sole();
        $this->assertSame($this->user->id, $account->user_id);
        $this->assertSame(self::CHANNEL_ID, $account->external_account_id);
        $this->assertSame('channel', $account->account_type->value);
        $this->assertSame('Ma chaîne', $account->display_name);
        $this->assertSame('@machaine', $account->username);
        $this->assertSame(SocialAccountStatus::Connected, $account->status);
        $this->assertSame(0, $account->tokens()->count(), 'Aucun token stocké : bot partagé.');

        // Vérifications faites auprès de Telegram : droits du bot ET statut de la personne.
        $members = $this->telegramCalls('getChatMember');
        $this->assertEqualsCanonicalizing([self::BOT_ID, self::TG_USER], array_map(fn ($m) => (int) $m['user_id'], $members));
        $confirmation = $this->telegramCalls('sendMessage')[1];
        $this->assertStringContainsString('« Ma chaîne » est relié(e) à SoxialConnect', $confirmation['text']);
        $this->assertTrue($confirmation['reply_markup']['remove_keyboard']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'user_id' => $this->user->id]);

        $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()
            ->assertJsonPath('data.0.platform', 'telegram')
            ->assertJsonPath('data.0.account_type', 'channel');
    }

    public function test_a_supergroup_is_linked_as_a_group(): void
    {
        $this->fakeTelegram([
            'getChat' => fn (array $d) => self::tgOk(['id' => (int) $d['chat_id'], 'type' => 'supergroup', 'title' => 'Mon groupe']),
            'getChatMember' => fn (array $d) => self::tgOk(['status' => (int) $d['user_id'] === self::BOT_ID ? 'member' : 'administrator']),
        ]);
        $this->startAndShare(2, '-1005550000');

        $account = SocialAccount::sole();
        $this->assertSame('group', $account->account_type->value);
        $this->assertNull($account->username);
        $this->assertSame('supergroup', $account->metadata['chat_type']);
    }

    public function test_channel_without_post_right_for_the_bot_is_refused(): void
    {
        $this->fakeTelegram(['getChatMember' => fn (array $d) => self::tgOk((int) $d['user_id'] === self::BOT_ID
            ? ['status' => 'administrator', 'can_post_messages' => false]
            : ['status' => 'creator'])]);
        $this->startAndShare();

        $this->assertSame(0, SocialAccount::count());
        $this->assertStringContainsString('« Publier des messages »', $this->replies()[1]);
    }

    public function test_a_person_who_is_not_admin_of_the_chat_cannot_link_it(): void
    {
        $this->fakeTelegram(['getChatMember' => fn (array $d) => self::tgOk((int) $d['user_id'] === self::BOT_ID
            ? ['status' => 'administrator', 'can_post_messages' => true]
            : ['status' => 'member'])]);
        $this->startAndShare();

        $this->assertSame(0, SocialAccount::count());
        $this->assertStringContainsString('propriétaire ou administrateur', $this->replies()[1]);
    }

    public function test_chat_shared_without_a_link_session_is_refused(): void
    {
        $this->webhook($this->privateMessage(['text' => '/start '.$this->linkCode()]))->assertOk();
        // Autre personne Telegram (pas de session), puis session expirée.
        $this->webhook($this->privateMessage(['chat_shared' => ['request_id' => 1, 'chat_id' => (int) self::CHANNEL_ID]], 9990003))->assertOk();
        $this->travel(11)->minutes();
        $this->webhook($this->privateMessage(['chat_shared' => ['request_id' => 1, 'chat_id' => (int) self::CHANNEL_ID]]))->assertOk();

        $this->assertSame(0, SocialAccount::count());
        $this->assertStringContainsString('Cette demande a expiré', $this->replies()[1]);
        $this->assertStringContainsString('Cette demande a expiré', $this->replies()[2]);
    }

    public function test_relinking_a_disconnected_destination_reactivates_it(): void
    {
        $existing = $this->telegramAccount($this->user, ['status' => SocialAccountStatus::Revoked]);
        $this->startAndShare();

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame(SocialAccountStatus::Connected, $existing->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    // --- Suivi des droits du bot ---------------------------------------------------------------

    public function test_bot_removed_then_restored_updates_the_destination_status(): void
    {
        $account = $this->telegramAccount($this->user);
        $revoked = $this->telegramAccount(User::factory()->create(), ['status' => SocialAccountStatus::Revoked]);
        $member = fn (array $new) => ['my_chat_member' => [
            'chat' => ['id' => (int) self::CHANNEL_ID, 'type' => 'channel', 'title' => 'Ma chaîne'],
            'from' => ['id' => self::TG_USER], 'date' => time(),
            'old_chat_member' => ['status' => 'administrator'], 'new_chat_member' => $new,
        ]];

        $this->webhook($member(['status' => 'kicked']))->assertOk();
        $this->assertSame(SocialAccountStatus::Error, $account->fresh()->status);
        $this->assertStringContainsString('Publier des messages', $account->fresh()->status_reason);

        $this->webhook($member(['status' => 'administrator', 'can_post_messages' => true]))->assertOk();
        $this->assertSame(SocialAccountStatus::Connected, $account->fresh()->status);
        $this->assertSame(SocialAccountStatus::Revoked, $revoked->fresh()->status, 'Une destination déconnectée n\'est jamais réactivée automatiquement.');
    }

    public function test_group_migrated_to_supergroup_keeps_its_destination(): void
    {
        $account = $this->telegramAccount($this->user, ['external_account_id' => '-4242', 'account_type' => 'group']);
        $this->webhook(['message' => ['message_id' => 1, 'chat' => ['id' => -4242, 'type' => 'group'], 'date' => time(), 'migrate_to_chat_id' => -1004242]])->assertOk();

        $this->assertSame('-1004242', $account->fresh()->external_account_id);
    }

    // --- Synchronisation, déconnexion, permissions ---------------------------------------------

    public function test_sync_reads_title_and_bot_rights_from_telegram(): void
    {
        $account = $this->telegramAccount($this->user, ['display_name' => 'Ancien titre']);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertOk()
            ->assertJsonPath('data.display_name', 'Ma chaîne');

        $this->fakeTelegram(['getChatMember' => fn () => self::tgOk(['status' => 'left'])]);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertOk()
            ->assertJsonPath('data.status', 'error');
    }

    public function test_disconnect_only_removes_the_link_and_the_bot_stays_in_the_chat(): void
    {
        $account = $this->telegramAccount($this->user);
        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame([], $this->telegramCalls('leaveChat'));
        $this->assertSame([], $this->telegramCalls);
        $this->assertNotNull(SocialAccount::find($account->id), 'Liaison conservée (historique), reconnectable.');
    }

    public function test_another_user_cannot_sync_or_disconnect_a_destination(): void
    {
        $account = $this->telegramAccount($this->user);
        $intruder = User::factory()->create();
        $this->actingAs($intruder)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertNotFound();
        $this->actingAs($intruder)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertNotFound();
        $this->assertSame(SocialAccountStatus::Connected, $account->fresh()->status);
    }

    public function test_the_bot_token_and_secret_are_never_stored(): void
    {
        $this->startAndShare();
        foreach (['social_accounts', 'webhook_events', 'audit_logs', 'social_tokens'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            $this->assertStringNotContainsString('TEST-bot-token', $dump, $table);
            $this->assertStringNotContainsString(self::WEBHOOK_SECRET, $dump, $table);
        }
    }
}
