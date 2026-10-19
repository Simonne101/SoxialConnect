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
use Tests\Feature\Social\Concerns\LinkedInFixtures;
use Tests\TestCase;

/** Connexion LinkedIn : URL et state, callback, erreurs, scope, identité, tokens chiffrés, reconnexion, sync. */
class LinkedInOAuthTest extends TestCase
{
    use LinkedInFixtures, RefreshDatabase;

    private const BACK = 'http://localhost:5173/social-accounts?oauth=linkedin&status=';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureLinkedIn();
        $this->user = User::factory()->create();
    }

    /** @return array{url: string, query: array<string, string>} */
    private function startConnect(?User $as = null): array
    {
        $this->app['auth']->forgetGuards();
        $url = $this->actingAs($as ?? $this->user)->fromSpa()->postJson('/api/v1/social/linkedin/connect')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'query' => $query];
    }

    private function oauthReturn(array $query, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/linkedin/callback?'.http_build_query($query));
    }

    /** @var array{0: array<string, mixed>, 1: int} réponse de l'échange du code */
    private array $tokenResponse = [[], 200];

    /** @var array<string, mixed> réponse userinfo */
    private array $memberResponse = [];

    private bool $faked = false;

    /** Faux serveur LinkedIn, enregistré UNE fois (les Http::fake s'empilent) ; réponses remplaçables. */
    private function fakeLinkedIn(array $token = [], array $member = [], int $tokenStatus = 200, bool $replaceToken = false): void
    {
        $this->tokenResponse = [$replaceToken ? $token : array_merge([
            'access_token' => self::LI_ACCESS,
            'expires_in' => 5184000,
            'scope' => 'email,openid,profile,w_member_social',
            'token_type' => 'Bearer',
            'id_token' => 'header.payload.signature',
        ], $token), $tokenStatus];
        $this->memberResponse = array_merge([
            'sub' => self::LI_SUB,
            'name' => 'Ada Lovelace',
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'picture' => 'https://media.licdn.com/photo.jpg',
            'email' => 'ada@exemple.test',
            'email_verified' => true,
        ], $member);
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            return match ($request->url()) {
                'https://www.linkedin.com/oauth/v2/accessToken' => Http::response($this->tokenResponse[0], $this->tokenResponse[1]),
                'https://api.linkedin.com/v2/userinfo' => Http::response(array_filter($this->memberResponse, fn ($v) => $v !== null)),
                default => null,
            };
        });
    }

    public function test_connect_returns_the_official_url_with_state_and_minimal_scopes(): void
    {
        ['url' => $url, 'query' => $q] = $this->startConnect();

        $this->assertStringStartsWith('https://www.linkedin.com/oauth/v2/authorization?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('test-linkedin-client-id', $q['client_id']);
        $this->assertSame('https://tunnel.exemple.test/oauth/linkedin/callback', $q['redirect_uri']);
        $this->assertSame('openid profile email w_member_social', $q['scope']);
        $this->assertSame(64, strlen($q['state']));
        $this->assertStringNotContainsString('test-linkedin-client-secret', $url);
        $this->assertStringNotContainsString('organization', $url, 'Aucune permission de page/organisation demandée.');
        Http::assertNothingSent();
    }

    public function test_connect_explains_missing_credentials_and_refuses_guests_and_unverified(): void
    {
        config(['services.linkedin.client_secret' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/linkedin/connect')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion LinkedIn n\'est pas configurée sur ce serveur (identifiants LinkedIn absents).');
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/social/linkedin/connect')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->fromSpa()->postJson('/api/v1/social/linkedin/connect')->assertForbidden();
    }

    public function test_valid_callback_connects_the_profile_with_an_encrypted_token(): void
    {
        $this->fakeLinkedIn();
        $state = $this->startConnect()['query']['state'];

        $this->oauthReturn(['state' => $state, 'code' => 'code-abc'])->assertRedirect(self::BACK.'connected');

        $account = SocialAccount::sole();
        $this->assertSame('linkedin', $account->platform->key);
        $this->assertSame(self::LI_SUB, $account->external_account_id);
        $this->assertSame('profile', $account->account_type->value);
        $this->assertSame('Ada Lovelace', $account->display_name);
        $this->assertSame('ada@exemple.test', $account->username);
        $this->assertSame('urn:li:person:'.self::LI_SUB, $account->metadata['person_urn']);

        $raw = DB::table('social_tokens')->sole();
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertStringNotContainsString(self::LI_ACCESS, $raw->access_token_encrypted);
        $this->assertSame(self::LI_ACCESS, SocialToken::sole()->access_token_encrypted);
        $this->assertNull($raw->refresh_token_encrypted, 'Aucun refresh token inventé.');
        $this->assertEqualsWithDelta(now()->addDays(60)->getTimestamp(), SocialToken::sole()->expires_at->getTimestamp(), 5);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'w_member_social', 'status' => 'granted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'user_id' => $this->user->id]);

        // Échange conforme à la documentation : formulaire, grant_type, code, client_id, client_secret, redirect_uri.
        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.linkedin.com/oauth/v2/accessToken'
            && $r->isForm() && $r['grant_type'] === 'authorization_code' && $r['code'] === 'code-abc'
            && $r['client_id'] === 'test-linkedin-client-id' && $r['client_secret'] === 'test-linkedin-client-secret'
            && $r['redirect_uri'] === 'https://tunnel.exemple.test/oauth/linkedin/callback');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.linkedin.com/v2/userinfo' && $r->hasHeader('Authorization', 'Bearer '.self::LI_ACCESS));
    }

    public function test_email_is_optional(): void
    {
        $this->fakeLinkedIn(member: ['email' => null, 'email_verified' => null]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'connected');

        $this->assertNull(SocialAccount::sole()->username);
    }

    public function test_a_second_member_is_added_without_replacing_the_first(): void
    {
        $this->fakeLinkedIn(['access_token' => 'AQX-member-a']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c1'])->assertRedirect(self::BACK.'connected');
        $this->fakeLinkedIn(['access_token' => 'AQX-member-b'], ['sub' => 'li-member-other', 'name' => 'Compte B']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])->assertRedirect(self::BACK.'connected');

        $accounts = SocialAccount::query()->orderBy('created_at')->get();
        $this->assertSame([self::LI_SUB, 'li-member-other'], $accounts->pluck('external_account_id')->all());
        $this->assertSame(['AQX-member-a', 'AQX-member-b'], $accounts->map(fn ($a) => SocialToken::where('social_account_id', $a->id)->where('status', 'active')->sole()->access_token_encrypted)->all());
        $this->assertDatabaseMissing('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    public function test_reconnecting_updates_the_same_account_and_replaces_the_token(): void
    {
        $this->fakeLinkedIn();
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c1'])->assertRedirect(self::BACK.'connected');
        $this->fakeLinkedIn(['access_token' => 'AQX-second-token'], ['name' => 'Ada L.']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])->assertRedirect(self::BACK.'connected');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('Ada L.', SocialAccount::sole()->display_name);
        $this->assertSame(1, SocialToken::where('status', 'active')->count());
        $this->assertSame('AQX-second-token', SocialToken::where('status', 'active')->sole()->access_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    public function test_callback_without_code_or_with_linkedin_error_connects_nothing(): void
    {
        $this->oauthReturn(['state' => $this->startConnect()['query']['state']])->assertRedirect(self::BACK.'error&code=OAUTH_FAILED');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'user_cancelled_authorize', 'error_description' => 'The user cancelled'])
            ->assertRedirect(self::BACK.'error&code=ACCESS_DENIED');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'user_cancelled_login'])
            ->assertRedirect(self::BACK.'error&code=ACCESS_DENIED');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'server_error'])
            ->assertRedirect(self::BACK.'error&code=OAUTH_FAILED');

        $this->assertSame(0, SocialAccount::count());
        Http::assertNothingSent();
    }

    public function test_invalid_reused_foreign_or_expired_state_is_refused(): void
    {
        $this->fakeLinkedIn();
        $this->oauthReturn(['state' => str_repeat('x', 64), 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $state = $this->startConnect()['query']['state'];
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'connected');
        $this->oauthReturn(['state' => $state, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        // State émis pour un AUTRE utilisateur (CSRF de connexion).
        $other = User::factory()->create();
        $foreign = $this->startConnect($other)['query']['state'];
        $this->oauthReturn(['state' => $foreign, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        // State expiré (10 minutes).
        $expired = $this->startConnect()['query']['state'];
        $this->travel(11)->minutes();
        $this->oauthReturn(['state' => $expired, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $this->assertSame(1, SocialAccount::count());
    }

    public function test_guest_on_callback_is_sent_to_login(): void
    {
        $this->app['auth']->forgetGuards();
        $this->get('/oauth/linkedin/callback?state=x&code=y')->assertRedirect('http://localhost:5173/login');
    }

    public function test_publish_permission_not_granted_connects_nothing(): void
    {
        $this->fakeLinkedIn(['scope' => 'openid,profile,email']);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])
            ->assertRedirect(self::BACK.'error&code=PUBLISH_SCOPE_DENIED');
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_token_exchange_errors_are_classified_without_leaking_anything(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logs) {
            $logs[] = [$e->message, $e->context];
        });

        foreach ([
            [['error' => 'invalid_client', 'error_description' => 'bad'], 401, 'OAUTH_CLIENT_INVALID'],
            [['error' => 'invalid_redirect_uri', 'error_description' => 'expired'], 400, 'OAUTH_FAILED'],
            [[], 503, 'NETWORK_ERROR'],
        ] as [$body, $status, $code]) {
            $this->fakeLinkedIn($body, tokenStatus: $status, replaceToken: true);
            $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])
                ->assertRedirect(self::BACK.'error&code='.$code);
        }

        $this->assertSame(0, SocialAccount::count());
        $dump = json_encode($logs);
        $this->assertStringNotContainsString('test-linkedin-client-secret', $dump);
        $this->assertStringNotContainsString(self::LI_ACCESS, $dump);
    }

    public function test_sync_rereads_the_member_and_detects_another_member(): void
    {
        $account = $this->linkedinAccount($this->user);
        $this->fakeLinkedIn(member: ['name' => 'Ada King']);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertOk()
            ->assertJsonPath('data.display_name', 'Ada King');

        $this->fakeLinkedIn(member: ['sub' => 'autre-membre', 'name' => 'X']);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertStatus(409);
    }

    public function test_disconnect_erases_the_tokens_locally(): void
    {
        $account = $this->linkedinAccount($this->user);
        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/social/accounts/{$account->id}")->assertOk()
            ->assertJsonPath('data.status', 'revoked');

        $this->assertSame(0, SocialToken::count());
        Http::assertNothingSent();
    }

    public function test_accounts_api_never_exposes_the_token(): void
    {
        $this->linkedinAccount($this->user);
        $content = $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()
            ->assertJsonPath('data.0.platform', 'linkedin')->getContent();

        $this->assertStringNotContainsString(self::LI_ACCESS, $content);
    }
}
