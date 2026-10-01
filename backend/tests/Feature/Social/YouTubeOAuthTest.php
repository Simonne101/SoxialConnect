<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/** Connexion YouTube : state, PKCE, callback, tokens chiffrés et jamais exposés, déconnexion, IDOR. */
class YouTubeOAuthTest extends TestCase
{
    use RefreshDatabase, YouTubeFixtures;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureYouTube();
        $this->user = User::factory()->create();
    }

    /** @return array{url: string, query: array<string, string>} */
    private function startConnect(?User $as = null): array
    {
        $this->app['auth']->forgetGuards(); // un seul utilisateur par requête (gardes réinitialisés)
        $url = $this->actingAs($as ?? $this->user)->fromSpa()->postJson('/api/v1/social/youtube/connect')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'query' => $query];
    }

    private function fakeGoogle(array $token = [], array $channel = []): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(array_merge([
                'access_token' => self::ACCESS_TOKEN,
                'refresh_token' => self::REFRESH_TOKEN,
                'expires_in' => 3599,
                'scope' => 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly',
                'token_type' => 'Bearer',
            ], $token)),
            'www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [array_merge([
                'id' => 'UC_real_channel',
                'snippet' => ['title' => 'Ma chaîne', 'customUrl' => '@machaine'],
            ], $channel)]]),
            'oauth2.googleapis.com/revoke' => Http::response('', 200),
        ]);
    }

    private function oauthReturn(array $query, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/youtube/callback?'.http_build_query($query));
    }

    // --- Démarrage ---------------------------------------------------------------------------

    public function test_connect_returns_a_google_url_with_state_pkce_and_minimal_scopes(): void
    {
        ['url' => $url, 'query' => $q] = $this->startConnect();

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        $this->assertSame(64, strlen($q['state']));
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertNotEmpty($q['code_challenge']);
        $this->assertSame('https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly', $q['scope']);
        $this->assertSame('http://localhost:5173/oauth/youtube/callback', $q['redirect_uri']);
        $this->assertSame('offline', $q['access_type']);
        $this->assertSame('code', $q['response_type']);
        $this->assertStringNotContainsString('test-client-secret', $url);
        Http::assertNothingSent();
    }

    public function test_two_connections_get_different_states(): void
    {
        $this->assertNotSame($this->startConnect()['query']['state'], $this->startConnect()['query']['state']);
    }

    public function test_guests_cannot_start_a_connection(): void
    {
        $this->fromSpa()->postJson('/api/v1/social/youtube/connect')->assertUnauthorized();
    }

    public function test_connect_explains_when_google_credentials_are_missing(): void
    {
        config(['services.youtube.client_secret' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/youtube/connect')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion YouTube n\'est pas configurée sur ce serveur (identifiants Google absents).');
    }

    // --- Callback ---------------------------------------------------------------------------

    public function test_valid_callback_connects_the_channel_and_encrypts_tokens(): void
    {
        $this->fakeGoogle();
        $state = $this->startConnect()['query']['state'];

        $this->oauthReturn(['state' => $state, 'code' => '4/auth-code'])
            ->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=connected');

        $account = SocialAccount::sole();
        $this->assertSame($this->user->id, $account->user_id);
        $this->assertSame('UC_real_channel', $account->external_account_id);
        $this->assertSame('Ma chaîne', $account->display_name);
        $this->assertSame('@machaine', $account->username);
        $this->assertSame('connected', $account->status->value);
        $this->assertSame('channel', $account->account_type->value);

        // Chiffré au repos (format versionné), déchiffrable uniquement côté serveur.
        $raw = DB::table('social_tokens')->sole();
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertStringStartsWith('v1:', $raw->refresh_token_encrypted);
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, $raw->access_token_encrypted);
        $this->assertSame(self::ACCESS_TOKEN, SocialToken::sole()->access_token_encrypted);
        $this->assertSame(self::REFRESH_TOKEN, SocialToken::sole()->refresh_token_encrypted);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'https://www.googleapis.com/auth/youtube.upload', 'status' => 'granted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'subject_id' => $account->id]);

        // Échange du code : PKCE + URI de retour exacte, secret envoyé uniquement à Google.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/token'
            && $r['grant_type'] === 'authorization_code' && $r['code'] === '4/auth-code'
            && strlen((string) $r['code_verifier']) === 96 && $r['redirect_uri'] === 'http://localhost:5173/oauth/youtube/callback');

        // Aucune réponse de l'API ne contient les tokens.
        $json = $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()
            ->assertJsonPath('data.0.platform', 'youtube')->assertJsonPath('data.0.status', 'connected')->getContent();
        foreach ([self::ACCESS_TOKEN, self::REFRESH_TOKEN, 'v1:', 'encrypted', 'test-client-secret'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $this->assertStringNotContainsString(self::ACCESS_TOKEN, (string) DB::table('audit_logs')->pluck('metadata')->implode(' '));
    }

    public function test_invalid_or_foreign_or_reused_state_is_refused(): void
    {
        $this->fakeGoogle();
        $this->oauthReturn(['state' => str_repeat('x', 64), 'code' => 'c'])->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=INVALID_STATE');
        $this->oauthReturn(['code' => 'c'])->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=INVALID_STATE');

        // State émis pour un autre utilisateur.
        $other = User::factory()->create();
        $foreign = $this->startConnect($other)['query']['state'];
        $this->oauthReturn(['state' => $foreign, 'code' => 'c'])->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=INVALID_STATE');

        // Usage unique.
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=connected');
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=INVALID_STATE');

        Http::assertSentCount(2); // token + channels : uniquement pour le callback valide
    }

    public function test_expired_state_is_refused(): void
    {
        $state = $this->startConnect()['query']['state'];
        $this->travel(11)->minutes();
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=INVALID_STATE');
        Http::assertNothingSent();
    }

    public function test_google_error_is_reported_without_token_exchange(): void
    {
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'error' => 'access_denied'])
            ->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=ACCESS_DENIED');
        Http::assertNothingSent();
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_refused_code_exchange_is_reported(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Bad Request'], 400)]);
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])
            ->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=TOKEN_REVOKED');
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_upload_scope_unchecked_by_the_user_is_refused_and_revoked(): void
    {
        $this->fakeGoogle(['scope' => 'https://www.googleapis.com/auth/youtube.readonly']);
        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])
            ->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=error&code=SCOPE_DENIED');
        $this->assertSame(0, SocialAccount::count());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke');
    }

    public function test_callback_without_session_user_goes_to_login(): void
    {
        $this->get('/oauth/youtube/callback?state=abc&code=c')->assertRedirect('http://localhost:5173/login');
        Http::assertNothingSent();
    }

    public function test_reconnection_updates_the_same_account_and_keeps_the_refresh_token(): void
    {
        $scope = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';
        Http::fake([
            // Google ne renvoie pas de refresh token la seconde fois.
            'oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => self::ACCESS_TOKEN, 'refresh_token' => self::REFRESH_TOKEN, 'expires_in' => 3599, 'scope' => $scope])
                ->push(['access_token' => 'ya29.second', 'expires_in' => 3599, 'scope' => $scope]),
            'www.googleapis.com/youtube/v3/channels*' => Http::sequence()
                ->push(['items' => [['id' => 'UC_real_channel', 'snippet' => ['title' => 'Ma chaîne']]]])
                ->push(['items' => [['id' => 'UC_real_channel', 'snippet' => ['title' => 'Nouveau nom']]]]),
        ]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])
            ->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=connected');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])
            ->assertRedirect('http://localhost:5173/social-accounts?oauth=youtube&status=connected');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('Nouveau nom', SocialAccount::sole()->display_name);
        $active = SocialToken::where('status', 'active')->sole();
        $this->assertSame('ya29.second', $active->access_token_encrypted);
        $this->assertSame(self::REFRESH_TOKEN, $active->refresh_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    // --- Déconnexion et propriété -------------------------------------------------------------

    public function test_disconnect_revokes_at_google_and_erases_tokens(): void
    {
        Http::fake(['oauth2.googleapis.com/revoke' => Http::response('', 200)]);
        $account = $this->youtubeAccount($this->user);

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")
            ->assertOk()->assertJsonPath('data.status', 'revoked');

        Http::assertSent(fn (Request $r) => $r->url() === 'https://oauth2.googleapis.com/revoke' && $r['token'] === self::REFRESH_TOKEN);
        $this->assertSame(0, SocialToken::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_REVOKED', 'subject_id' => $account->id]);
    }

    public function test_another_users_account_cannot_be_disconnected_or_synced(): void
    {
        $account = $this->youtubeAccount($this->user);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertNotFound();
        $this->actingAs($intruder)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertNotFound();
        $this->assertSame('connected', $account->fresh()->status->value);
        $this->assertSame(1, SocialToken::count());
        Http::assertNothingSent();
    }

    public function test_sync_refreshes_the_channel_name(): void
    {
        Http::fake(['www.googleapis.com/youtube/v3/channels*' => Http::response(['items' => [['id' => 'UC_test_channel', 'snippet' => ['title' => 'Nom à jour', 'customUrl' => '@ajour']]]])]);
        $account = $this->youtubeAccount($this->user);

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")
            ->assertOk()->assertJsonPath('data.display_name', 'Nom à jour')->assertJsonPath('data.username', '@ajour');
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));
    }
}
