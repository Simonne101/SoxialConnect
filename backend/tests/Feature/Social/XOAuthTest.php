<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\XFixtures;
use Tests\TestCase;

/** Connexion X : URL, state et PKCE, callback, erreurs, scope, identité, tokens chiffrés, reconnexion, sync, déconnexion. */
class XOAuthTest extends TestCase
{
    use RefreshDatabase, XFixtures;

    private const BACK = 'http://localhost:5173/social-accounts?oauth=x&status=';

    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    private const REVOKE_URL = 'https://api.x.com/2/oauth2/revoke';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureX();
        $this->user = User::factory()->create();
    }

    /** @return array{url: string, query: array<string, string>} */
    private function startConnect(?User $as = null): array
    {
        $this->app['auth']->forgetGuards();
        $url = $this->actingAs($as ?? $this->user)->fromSpa()->postJson('/api/v1/social/x/connect')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'query' => $query];
    }

    private function oauthReturn(array $query, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/x/callback?'.http_build_query($query));
    }

    /** @var array{0: array<string, mixed>, 1: int} réponse de l'échange du code */
    private array $tokenResponse = [[], 200];

    /** @var array{0: array<string, mixed>, 1: int} réponse de users/me */
    private array $meResponse = [[], 200];

    private bool $faked = false;

    /** Faux serveur X, enregistré UNE fois (les Http::fake s'empilent) ; réponses remplaçables. */
    private function fakeX(array $token = [], array $user = [], int $tokenStatus = 200, bool $replaceToken = false, int $meStatus = 200): void
    {
        $this->tokenResponse = [$replaceToken ? $token : array_merge([
            'token_type' => 'bearer',
            'expires_in' => 7200,
            'access_token' => self::X_ACCESS,
            'scope' => 'tweet.write users.read tweet.read offline.access',
            'refresh_token' => self::X_REFRESH,
        ], $token), $tokenStatus];
        $this->meResponse = [$meStatus === 200 ? ['data' => array_merge([
            'id' => self::X_USER_ID,
            'name' => 'Ada Lovelace',
            'username' => 'ada',
            'profile_image_url' => 'https://pbs.twimg.com/profile_images/ada_normal.jpg',
        ], $user)] : ['title' => 'Forbidden', 'type' => 'about:blank', 'status' => $meStatus], $meStatus];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            return match (strtok($request->url(), '?')) {
                self::TOKEN_URL => Http::response($this->tokenResponse[0], $this->tokenResponse[1]),
                'https://api.x.com/2/users/me' => Http::response($this->meResponse[0], $this->meResponse[1]),
                self::REVOKE_URL => Http::response(['revoked' => true]),
                default => null,
            };
        });
    }

    public function test_connect_returns_the_official_url_with_state_pkce_and_minimal_scopes(): void
    {
        ['url' => $url, 'query' => $q] = $this->startConnect();

        $this->assertStringStartsWith('https://x.com/i/oauth2/authorize?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame(self::X_CLIENT_ID, $q['client_id']);
        $this->assertSame(self::X_REDIRECT, $q['redirect_uri']);
        $this->assertSame('users.read tweet.read tweet.write offline.access', $q['scope']);
        $this->assertSame(64, strlen($q['state']));
        $this->assertSame('S256', $q['code_challenge_method']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $q['code_challenge']);
        $this->assertStringNotContainsString(self::X_CLIENT_SECRET, $url);
        $this->assertArrayNotHasKey('code_verifier', $q, 'Le vérificateur PKCE ne quitte jamais le serveur.');
        Http::assertNothingSent();
    }

    public function test_connect_explains_missing_credentials_and_refuses_guests_and_unverified(): void
    {
        config(['services.x.redirect' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/x/connect')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion X n\'est pas configurée sur ce serveur (identifiants X absents).');
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/social/x/connect')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->fromSpa()->postJson('/api/v1/social/x/connect')->assertForbidden();
    }

    public function test_valid_callback_connects_the_account_with_encrypted_tokens(): void
    {
        $this->fakeX();
        ['query' => $q] = $this->startConnect();

        $this->oauthReturn(['state' => $q['state'], 'code' => 'code-abc'])->assertRedirect(self::BACK.'connected');

        $account = SocialAccount::sole();
        $this->assertSame('x', $account->platform->key);
        $this->assertSame(self::X_USER_ID, $account->external_account_id);
        $this->assertSame('profile', $account->account_type->value);
        $this->assertSame('Ada Lovelace', $account->display_name);
        $this->assertSame('@ada', $account->username);
        $this->assertSame('connected', $account->status->value);
        $this->assertSame('https://pbs.twimg.com/profile_images/ada_normal.jpg', $account->metadata['picture_url']);

        $raw = DB::table('social_tokens')->sole();
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertStringNotContainsString(self::X_ACCESS, $raw->access_token_encrypted);
        $this->assertStringNotContainsString(self::X_REFRESH, $raw->refresh_token_encrypted);
        $token = SocialToken::sole();
        $this->assertSame(self::X_ACCESS, $token->access_token_encrypted);
        $this->assertSame(self::X_REFRESH, $token->refresh_token_encrypted);
        $this->assertSame('Bearer', $token->token_type);
        $this->assertEqualsWithDelta(now()->addHours(2)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'tweet.write', 'status' => 'granted']);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'offline.access', 'status' => 'granted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'user_id' => $this->user->id]);

        // Échange conforme à la documentation X : formulaire, client en HTTP Basic (jamais dans le corps),
        // PKCE : le vérificateur envoyé correspond au code_challenge de l'URL d'autorisation.
        Http::assertSent(function (Request $r) use ($q) {
            return $r->url() === self::TOKEN_URL && $r->isForm()
                && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::X_CLIENT_ID.':'.self::X_CLIENT_SECRET))
                && $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-abc'
                && $r['redirect_uri'] === self::X_REDIRECT
                && ! isset($r['client_secret'])
                && rtrim(strtr(base64_encode(hash('sha256', $r['code_verifier'], true)), '+/', '-_'), '=') === $q['code_challenge'];
        });
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.x.com/2/users/me?')
            && $r['user.fields'] === 'profile_image_url' && $r->hasHeader('Authorization', 'Bearer '.self::X_ACCESS));
    }

    public function test_reconnecting_updates_the_same_account_and_replaces_the_token(): void
    {
        $this->fakeX();
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c1'])->assertRedirect(self::BACK.'connected');
        $this->fakeX(['access_token' => 'x-second-access'], ['name' => 'Ada L.', 'username' => 'ada_l']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])->assertRedirect(self::BACK.'connected');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('Ada L.', SocialAccount::sole()->display_name);
        $this->assertSame('@ada_l', SocialAccount::sole()->username);
        $this->assertSame(1, SocialToken::where('status', 'active')->count());
        $this->assertSame('x-second-access', SocialToken::where('status', 'active')->sole()->access_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    public function test_callback_without_code_or_with_x_error_connects_nothing(): void
    {
        $this->oauthReturn(['state' => $this->startConnect()['query']['state']])->assertRedirect(self::BACK.'error&code=OAUTH_FAILED');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'access_denied'])
            ->assertRedirect(self::BACK.'error&code=ACCESS_DENIED');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'server_error'])
            ->assertRedirect(self::BACK.'error&code=OAUTH_FAILED');

        $this->assertSame(0, SocialAccount::count());
        Http::assertNothingSent();
    }

    public function test_invalid_reused_foreign_or_expired_state_is_refused(): void
    {
        $this->fakeX();
        $this->oauthReturn(['state' => str_repeat('x', 64), 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');
        $this->oauthReturn(['code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'connected');
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        // State émis pour un AUTRE utilisateur (CSRF de connexion).
        $foreign = $this->startConnect(User::factory()->create())['query']['state'];
        $this->oauthReturn(['state' => $foreign, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        // State émis pour un autre réseau.
        $this->fakeLinkedInConfig();
        $linkedin = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/linkedin/connect')->json('data.authorization_url');
        parse_str((string) parse_url($linkedin, PHP_URL_QUERY), $lq);
        $this->oauthReturn(['state' => $lq['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        // State expiré (10 minutes).
        $expired = $this->startConnect()['query']['state'];
        $this->travel(11)->minutes();
        $this->oauthReturn(['state' => $expired, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $this->assertSame(1, SocialAccount::count());
        Http::assertSentCount(2);
    }

    private function fakeLinkedInConfig(): void
    {
        config([
            'services.linkedin.client_id' => 'li-id',
            'services.linkedin.client_secret' => 'li-secret',
            'services.linkedin.redirect' => 'https://tunnel.exemple.test/oauth/linkedin/callback',
        ]);
    }

    public function test_guest_on_callback_is_sent_to_login(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/oauth/x/callback?state=x&code=y')->assertRedirect('http://localhost:5173/login');
    }

    public function test_publish_permission_not_granted_connects_nothing(): void
    {
        $this->fakeX(['scope' => 'users.read tweet.read offline.access']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])
            ->assertRedirect(self::BACK.'error&code=PUBLISH_SCOPE_DENIED');
        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, SocialToken::count());
    }

    public function test_token_exchange_and_api_access_errors_are_classified_without_leaking_anything(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = [$e->message, $e->context];
        });

        foreach ([
            [['error' => 'unauthorized_client', 'error_description' => 'Missing valid authorization header'], 401, 'OAUTH_CLIENT_INVALID'],
            [['error' => 'invalid_request', 'error_description' => 'Value passed for the authorization code was invalid.'], 400, 'OAUTH_FAILED'],
            [[], 503, 'NETWORK_ERROR'],
        ] as [$body, $status, $code]) {
            $this->fakeX($body, tokenStatus: $status, replaceToken: true);
            $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])
                ->assertRedirect(self::BACK.'error&code='.$code);
        }

        // Application sans accès à l'API (offre ou crédits) : users/me répond 403.
        $this->fakeX(meStatus: 403);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])
            ->assertRedirect(self::BACK.'error&code=API_ACCESS_DENIED');

        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, SocialToken::count());
        $dump = json_encode($logs);
        $this->assertStringNotContainsString(self::X_CLIENT_SECRET, $dump);
        $this->assertStringNotContainsString(self::X_ACCESS, $dump);
        $this->assertStringNotContainsString(self::X_REFRESH, $dump);
        $this->assertStringNotContainsString('authorization code was invalid', $dump);
    }

    public function test_sync_rereads_the_profile_and_detects_another_account(): void
    {
        $account = $this->xAccount($this->user);
        $this->fakeX(user: ['name' => 'Ada King', 'username' => 'adaking']);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertOk()
            ->assertJsonPath('data.display_name', 'Ada King')
            ->assertJsonPath('data.username', '@adaking');

        $this->fakeX(user: ['id' => '999', 'username' => 'autre']);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertStatus(409);
    }

    public function test_disconnect_revokes_both_tokens_at_x_and_erases_them_locally(): void
    {
        $this->fakeX();
        $account = $this->xAccount($this->user);
        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame(0, SocialToken::count());
        $basic = 'Basic '.base64_encode(self::X_CLIENT_ID.':'.self::X_CLIENT_SECRET);
        Http::assertSent(fn (Request $r) => $r->url() === self::REVOKE_URL && $r->hasHeader('Authorization', $basic)
            && $r['token'] === self::X_REFRESH && $r['token_type_hint'] === 'refresh_token');
        Http::assertSent(fn (Request $r) => $r->url() === self::REVOKE_URL && $r['token'] === self::X_ACCESS && $r['token_type_hint'] === 'access_token');
    }

    public function test_disconnect_still_happens_when_x_is_unreachable(): void
    {
        Http::fake([self::REVOKE_URL => Http::failedConnection()]);
        $account = $this->xAccount($this->user);

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->assertSame(0, SocialToken::count());
    }

    public function test_accounts_api_never_exposes_the_tokens(): void
    {
        $this->xAccount($this->user);
        $content = $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()
            ->assertJsonPath('data.0.platform', 'x')
            ->assertJsonPath('data.0.username', '@ada')->getContent();

        $this->assertStringNotContainsString(self::X_ACCESS, $content);
        $this->assertStringNotContainsString(self::X_REFRESH, $content);
    }

    public function test_another_user_cannot_sync_or_disconnect_the_account(): void
    {
        $account = $this->xAccount($this->user);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertNotFound();
        $this->actingAs($intruder)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertNotFound();
        $this->assertSame(1, SocialToken::count());
        Http::assertNothingSent();
    }
}
