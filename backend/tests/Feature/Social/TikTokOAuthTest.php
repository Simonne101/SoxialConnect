<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\TikTokFixtures;
use Tests\TestCase;

/** Connexion TikTok : state, callback, scope video.publish, tokens chiffrés, reconnexion, sync, déconnexion, IDOR. */
class TikTokOAuthTest extends TestCase
{
    use RefreshDatabase, TikTokFixtures;

    private User $user;

    private const BACK = 'http://localhost:5173/social-accounts?oauth=tiktok&status=';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureTikTok();
        $this->user = User::factory()->create();
    }

    /** @return array{url: string, query: array<string, string>} */
    private function startConnect(?User $as = null): array
    {
        $this->app['auth']->forgetGuards();
        $url = $this->actingAs($as ?? $this->user)->fromSpa()->postJson('/api/v1/social/tiktok/connect')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'query' => $query];
    }

    private function oauthReturn(array $query, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/tiktok/callback?'.http_build_query($query));
    }

    private function fakeTikTok(array $token = [], array $user = []): void
    {
        Http::fake([
            'open.tiktokapis.com/v2/oauth/token/' => Http::response(array_merge([
                'access_token' => self::TT_ACCESS,
                'expires_in' => 86400,
                'open_id' => self::TT_OPEN_ID,
                'refresh_token' => self::TT_REFRESH,
                'refresh_expires_in' => 31536000,
                'scope' => 'user.info.basic,video.publish',
                'token_type' => 'Bearer',
            ], $token)),
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => array_merge([
                'open_id' => self::TT_OPEN_ID, 'display_name' => 'Mon TikTok',
            ], $user)], 'error' => ['code' => 'ok', 'message' => '', 'log_id' => 'x']]),
            'open.tiktokapis.com/v2/oauth/revoke/' => Http::response([]),
        ]);
    }

    public function test_connect_returns_the_official_url_with_state_and_minimal_scopes(): void
    {
        ['url' => $url, 'query' => $q] = $this->startConnect();

        $this->assertStringStartsWith('https://www.tiktok.com/v2/auth/authorize/?', $url);
        $this->assertSame('test-client-key', $q['client_key']);
        $this->assertSame('user.info.basic,video.publish', $q['scope']);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('https://tunnel.exemple.test/oauth/tiktok/callback', $q['redirect_uri']);
        $this->assertSame(64, strlen($q['state']));
        $this->assertArrayNotHasKey('code_challenge', $q);
        $this->assertStringNotContainsString('test-tiktok-client-secret', $url);
        Http::assertNothingSent();
    }

    public function test_youtube_connect_still_works_through_the_generic_route(): void
    {
        config(['services.youtube.client_id' => 'id', 'services.youtube.client_secret' => 's', 'services.youtube.redirect' => 'http://localhost:5173/oauth/youtube/callback']);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/youtube/connect')->assertOk()
            ->assertJsonPath('data.authorization_url', fn ($u) => str_starts_with($u, 'https://accounts.google.com/'));
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/facebook/connect')->assertNotFound();
    }

    public function test_connect_explains_missing_credentials_and_refuses_guests(): void
    {
        config(['services.tiktok.client_secret' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/tiktok/connect')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion TikTok n\'est pas configurée sur ce serveur (identifiants TikTok absents).');
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/social/tiktok/connect')->assertUnauthorized();
    }

    public function test_valid_callback_connects_the_creator_with_encrypted_tokens(): void
    {
        $this->fakeTikTok();
        $state = $this->startConnect()['query']['state'];

        $this->oauthReturn(['state' => $state, 'code' => 'code-abc', 'scopes' => 'user.info.basic,video.publish'])
            ->assertRedirect(self::BACK.'connected');

        $account = SocialAccount::sole();
        $this->assertSame(self::TT_OPEN_ID, $account->external_account_id);
        $this->assertSame('creator', $account->account_type->value);
        $this->assertSame('Mon TikTok', $account->display_name);
        $this->assertSame('tiktok', $account->platform->key);

        $raw = DB::table('social_tokens')->sole();
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertStringStartsWith('v1:', $raw->refresh_token_encrypted);
        $this->assertNotNull($raw->refresh_expires_at);
        $this->assertSame(self::TT_ACCESS, SocialToken::sole()->access_token_encrypted);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'video.publish', 'status' => 'granted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'subject_id' => $account->id]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://open.tiktokapis.com/v2/oauth/token/'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-abc' && $r['client_key'] === 'test-client-key'
            && $r['redirect_uri'] === 'https://tunnel.exemple.test/oauth/tiktok/callback');

        $json = $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->getContent();
        foreach ([self::TT_ACCESS, self::TT_REFRESH, 'test-tiktok-client-secret', 'v1:'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString(self::TT_ACCESS, (string) DB::table('audit_logs')->pluck('metadata')->implode(' '));
    }

    public function test_invalid_foreign_expired_and_reused_states_are_refused(): void
    {
        $this->fakeTikTok();
        $this->oauthReturn(['state' => str_repeat('x', 64), 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $foreign = $this->startConnect(User::factory()->create())['query']['state'];
        $this->oauthReturn(['state' => $foreign, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'connected');
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $expired = $this->startConnect()['query']['state'];
        $this->travel(11)->minutes();
        $this->oauthReturn(['state' => $expired, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        Http::assertSentCount(2); // token + profil : uniquement pour le callback valide
    }

    public function test_refused_authorization_and_invalid_code(): void
    {
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'error' => 'access_denied'])->assertRedirect(self::BACK.'error&code=ACCESS_DENIED');
        Http::assertNothingSent();

        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Authorization code is expired.', 'log_id' => 'L'])]);
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'périmé'])->assertRedirect(self::BACK.'error&code=TOKEN_REVOKED');
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_missing_publish_scope_is_refused_and_revoked(): void
    {
        $this->fakeTikTok(['scope' => 'user.info.basic']);
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=SCOPE_DENIED');

        $this->assertSame(0, SocialAccount::count());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/' && $r['token'] === self::TT_ACCESS);
    }

    public function test_reconnection_with_the_same_open_id_reuses_the_account(): void
    {
        $this->fakeTikTok();
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c1']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])->assertRedirect(self::BACK.'connected');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame(1, SocialToken::where('status', 'active')->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    public function test_sync_disconnect_and_ownership(): void
    {
        $account = $this->tiktokAccount($this->user);
        Http::fake([
            'open.tiktokapis.com/v2/user/info/*' => Http::response(['data' => ['user' => ['open_id' => self::TT_OPEN_ID, 'display_name' => 'Nom à jour']], 'error' => ['code' => 'ok']]),
            'open.tiktokapis.com/v2/oauth/revoke/' => Http::response([]),
        ]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertNotFound();
        $this->actingAs($intruder)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertNotFound();
        $this->actingAs($intruder)->fromSpa()->getJson("/api/v1/social/accounts/{$account->id}/tiktok/creator-info")->assertNotFound();
        Http::assertNothingSent();

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")
            ->assertOk()->assertJsonPath('data.display_name', 'Nom à jour');

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")
            ->assertOk()->assertJsonPath('data.status', 'revoked');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://open.tiktokapis.com/v2/oauth/revoke/' && $r['token'] === self::TT_ACCESS && $r['client_key'] === 'test-client-key');
        $this->assertSame(0, SocialToken::count());
    }

    public function test_disconnect_still_happens_locally_when_tiktok_revocation_fails(): void
    {
        $account = $this->tiktokAccount($this->user);
        Http::fake(['open.tiktokapis.com/v2/oauth/revoke/' => Http::response(['error' => 'server_error'], 500)]);

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertOk();
        $this->assertSame(0, SocialToken::count());
        $this->assertSame('revoked', $account->fresh()->status->value);
    }

    public function test_creator_info_endpoint_returns_publishing_options_without_tokens(): void
    {
        $account = $this->tiktokAccount($this->user);
        Http::fake(['open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response($this->creatorInfo(['duet_disabled' => true]))]);

        $json = $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/social/accounts/{$account->id}/tiktok/creator-info")
            ->assertOk()
            ->assertJsonPath('data.nickname', 'Créateur test')
            ->assertJsonPath('data.privacy_level_options.2', 'SELF_ONLY')
            ->assertJsonPath('data.duet_disabled', true)
            ->assertJsonPath('data.max_video_post_duration_sec', 300)
            ->getContent();
        $this->assertStringNotContainsString(self::TT_ACCESS, $json);
    }
}
