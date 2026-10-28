<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\FacebookFixtures;
use Tests\TestCase;

/**
 * Connexion Facebook (Pages) : URL et state, callback, jeton longue durée, permissions, Pages
 * publiables, jetons de Page chiffrés, reconnexion, erreurs, synchronisation. Aucun appel réel.
 */
class FacebookOAuthTest extends TestCase
{
    use FacebookFixtures, RefreshDatabase;

    private const BACK = 'http://localhost:5173/social-accounts?oauth=facebook&status=';

    private User $user;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> réponses du faux Graph, par chemin */
    private array $graph = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureFacebook();
        $this->user = User::factory()->create();
    }

    /** @return array{url: string, query: array<string, string>} */
    private function startConnect(?User $as = null): array
    {
        $this->app['auth']->forgetGuards();
        $url = $this->actingAs($as ?? $this->user)->fromSpa()->postJson('/api/v1/social/facebook/connect')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'query' => $query];
    }

    private function oauthReturn(array $query, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/facebook/callback?'.http_build_query($query));
    }

    /** Faux API Graph, enregistré UNE fois (les Http::fake s'empilent) ; réponses remplaçables par chemin. */
    private function fakeGraph(array $overrides = []): void
    {
        // Union (et non array_merge) : l'identifiant numérique de la Page doit rester une clé.
        $this->graph = $overrides + [
            'oauth/access_token' => [['access_token' => self::FB_USER_TOKEN, 'token_type' => 'bearer', 'expires_in' => 5183944], 200],
            'me/permissions' => [['data' => [
                ['permission' => 'pages_show_list', 'status' => 'granted'],
                ['permission' => 'pages_manage_posts', 'status' => 'granted'],
                ['permission' => 'pages_read_engagement', 'status' => 'granted'],
                ['permission' => 'read_insights', 'status' => 'declined'],
                ['permission' => 'public_profile', 'status' => 'granted'],
            ]], 200],
            'me' => [['id' => '9988776655', 'name' => 'Ada Lovelace'], 200],
            'me/accounts' => [['data' => [
                ['id' => self::FB_PAGE_ID, 'name' => 'Boulangerie Ada', 'access_token' => self::FB_PAGE_TOKEN, 'tasks' => ['ANALYZE', 'ADVERTISE', 'MODERATE', 'CREATE_CONTENT', 'MANAGE'], 'category' => 'Boulangerie', 'picture' => ['data' => ['url' => 'https://scontent.exemple.test/p.jpg']]],
                ['id' => '555000111', 'name' => 'Page lecture seule', 'access_token' => 'EAAG-readonly-page', 'tasks' => ['ANALYZE']],
            ], 'paging' => ['cursors' => ['before' => 'b', 'after' => 'a']]], 200],
            self::FB_PAGE_ID => [['id' => self::FB_PAGE_ID, 'name' => 'Boulangerie Ada & Fils', 'category' => 'Boulangerie'], 200],
        ];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            $path = substr((string) parse_url($request->url(), PHP_URL_PATH), strlen('/v23.0/'));
            if (! str_starts_with($request->url(), self::FB_GRAPH) || ! isset($this->graph[$path])) {
                return null;
            }

            return Http::response(...$this->graph[$path]);
        });
    }

    public function test_connect_returns_the_official_url_with_state_and_page_scopes(): void
    {
        ['url' => $url, 'query' => $q] = $this->startConnect();

        $this->assertStringStartsWith('https://www.facebook.com/v23.0/dialog/oauth?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('1234567890123456', $q['client_id']);
        $this->assertSame('https://tunnel.exemple.test/oauth/facebook/callback', $q['redirect_uri']);
        $this->assertSame('pages_show_list,pages_manage_posts,pages_read_engagement,read_insights', $q['scope']);
        $this->assertSame(64, strlen($q['state']));
        $this->assertStringNotContainsString(self::FB_SECRET, $url);
        Http::assertNothingSent();
    }

    public function test_a_facebook_login_for_business_configuration_replaces_the_scopes(): void
    {
        config(['services.facebook.login_config_id' => '778899']);
        $q = $this->startConnect()['query'];

        $this->assertSame('778899', $q['config_id']);
        $this->assertArrayNotHasKey('scope', $q);
    }

    public function test_connect_explains_missing_credentials_and_refuses_guests_and_unverified(): void
    {
        config(['services.facebook.client_secret' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/facebook/connect')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion Facebook n\'est pas configurée sur ce serveur (identifiants Meta absents).');
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/social/facebook/connect')->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->fromSpa()->postJson('/api/v1/social/facebook/connect')->assertForbidden();
    }

    public function test_valid_callback_connects_only_publishable_pages_with_encrypted_page_tokens(): void
    {
        $this->fakeGraph();
        $state = $this->startConnect()['query']['state'];

        $this->oauthReturn(['state' => $state, 'code' => 'code-abc'])->assertRedirect(self::BACK.'connected&count=1');

        $account = SocialAccount::sole();
        $this->assertSame('facebook', $account->platform->key);
        $this->assertSame(self::FB_PAGE_ID, $account->external_account_id);
        $this->assertSame('page', $account->account_type->value);
        $this->assertSame('Boulangerie Ada', $account->display_name);
        $this->assertSame('Boulangerie', $account->metadata['category']);
        $this->assertSame('9988776655', $account->metadata['facebook_user_id']);

        // Seul le jeton de Page est conservé, chiffré, sans expiration ; le jeton utilisateur ne l'est jamais.
        $raw = DB::table('social_tokens')->sole();
        $this->assertSame('page', $raw->token_kind);
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertStringNotContainsString(self::FB_PAGE_TOKEN, $raw->access_token_encrypted);
        $this->assertSame(self::FB_PAGE_TOKEN, SocialToken::sole()->access_token_encrypted);
        $this->assertNull($raw->expires_at);
        $this->assertNull($raw->refresh_token_encrypted);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'pages_manage_posts', 'status' => 'granted', 'is_required' => true]);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'read_insights', 'status' => 'missing', 'is_required' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'user_id' => $this->user->id]);

        // Échange du code, puis jeton longue durée (fb_exchange_token) ; appsecret_proof sur les appels Graph.
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::FB_GRAPH.'oauth/access_token') && ($r->data()['code'] ?? null) === 'code-abc'
            && ($r->data()['client_secret'] ?? null) === self::FB_SECRET && ($r->data()['redirect_uri'] ?? null) === 'https://tunnel.exemple.test/oauth/facebook/callback');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::FB_GRAPH.'oauth/access_token') && ($r->data()['grant_type'] ?? null) === 'fb_exchange_token' && ($r->data()['fb_exchange_token'] ?? null) === self::FB_USER_TOKEN);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::FB_GRAPH.'me/accounts') && ($r->data()['appsecret_proof'] ?? null) === $this->fbProof(self::FB_USER_TOKEN)
            && $r->hasHeader('Authorization', 'Bearer '.self::FB_USER_TOKEN));
        // Aucun jeton ni clé secrète dans une URL (elles peuvent finir dans un journal).
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), self::FB_USER_TOKEN) || str_contains($r->url(), self::FB_PAGE_TOKEN) || str_contains($r->url(), self::FB_SECRET));
    }

    public function test_declining_the_publishing_permission_connects_nothing(): void
    {
        $this->fakeGraph(['me/permissions' => [['data' => [
            ['permission' => 'pages_show_list', 'status' => 'granted'],
            ['permission' => 'pages_manage_posts', 'status' => 'declined'],
        ]], 200]]);

        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=PUBLISH_SCOPE_DENIED');

        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, SocialToken::count());
    }

    public function test_no_page_or_no_publishable_page_is_explained(): void
    {
        $this->fakeGraph(['me/accounts' => [['data' => []], 200]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=NO_PAGES');

        $this->fakeGraph(['me/accounts' => [['data' => [['id' => '1', 'name' => 'Lecture', 'access_token' => 'EAAG-ro', 'tasks' => ['ANALYZE']]]], 200]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=NO_PUBLISHABLE_PAGE');

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_reconnecting_updates_the_same_page_and_replaces_its_token(): void
    {
        $this->fakeGraph();
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c1'])->assertRedirect(self::BACK.'connected&count=1');
        $page = $this->graph['me/accounts'][0]['data'][0];
        $this->fakeGraph(['me/accounts' => [['data' => [array_merge($page, ['access_token' => 'EAAG-second-page-token', 'name' => 'Boulangerie Ada (nouveau nom)'])]], 200]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])->assertRedirect(self::BACK.'connected&count=1');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('Boulangerie Ada (nouveau nom)', SocialAccount::sole()->display_name);
        $this->assertSame(1, SocialToken::where('status', 'active')->count());
        $this->assertSame('EAAG-second-page-token', SocialToken::where('status', 'active')->sole()->access_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    public function test_facebook_errors_and_invalid_state_connect_nothing(): void
    {
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'access_denied', 'error_reason' => 'user_denied'])
            ->assertRedirect(self::BACK.'error&code=ACCESS_DENIED');
        $this->oauthReturn(['state' => $this->startConnect()['query']['state']])->assertRedirect(self::BACK.'error&code=OAUTH_FAILED');
        $this->oauthReturn(['state' => 'etat-inconnu', 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $other = User::factory()->create();
        $foreign = $this->startConnect($other)['query']['state'];
        $this->oauthReturn(['state' => $foreign, 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');

        $this->assertSame(0, SocialAccount::count());
        Http::assertNothingSent();
    }

    public function test_an_expired_code_or_invalid_app_secret_is_explained_without_leaking_details(): void
    {
        $this->fakeGraph(['oauth/access_token' => [['error' => ['message' => 'Error validating client secret.', 'type' => 'OAuthException', 'code' => 1]], 400]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=OAUTH_CLIENT_INVALID');

        $this->fakeGraph(['oauth/access_token' => [['error' => ['message' => 'This authorization code has expired.', 'type' => 'OAuthException', 'code' => 100]], 400]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=OAUTH_FAILED');

        $this->assertSame(0, SocialAccount::count());
    }

    public function test_sync_rereads_the_page_and_a_revoked_token_requires_reconnection(): void
    {
        $account = $this->facebookPage($this->user);
        $this->fakeGraph();

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")
            ->assertOk()->assertJsonPath('message', 'Page synchronisée.');
        $this->assertSame('Boulangerie Ada & Fils', $account->fresh()->display_name);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::FB_GRAPH.self::FB_PAGE_ID) && ($r->data()['appsecret_proof'] ?? null) === $this->fbProof(self::FB_PAGE_TOKEN));

        $this->fakeGraph([self::FB_PAGE_ID => [['error' => ['message' => 'Error validating access token: The user has not authorized application.', 'type' => 'OAuthException', 'code' => 190, 'error_subcode' => 458]], 400]]);
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertStatus(409);

        $this->assertSame('expired', $account->fresh()->status->value);
        $this->assertSame('invalid', SocialToken::sole()->status->value);
    }
}
