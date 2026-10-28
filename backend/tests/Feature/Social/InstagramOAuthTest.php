<?php

namespace Tests\Feature\Social;

use App\Domain\Social\Tokens\InstagramTokenManager;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\InstagramFixtures;
use Tests\TestCase;

/**
 * Connexion Instagram (comptes professionnels) : URL et state, callback, jeton longue durée,
 * permissions, compte personnel refusé, reconnexion, renouvellement du jeton, synchronisation.
 */
class InstagramOAuthTest extends TestCase
{
    use InstagramFixtures, RefreshDatabase;

    private const BACK = 'http://localhost:5173/social-accounts?oauth=instagram&status=';

    private User $user;

    /** @var array<string, array{0: array<string, mixed>, 1: int}> réponses du faux Instagram, par clé */
    private array $ig = [];

    private bool $faked = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->configureInstagram();
        $this->user = User::factory()->create();
    }

    /** @return array{url: string, query: array<string, string>} */
    private function startConnect(?User $as = null): array
    {
        $this->app['auth']->forgetGuards();
        $url = $this->actingAs($as ?? $this->user)->fromSpa()->postJson('/api/v1/social/instagram/connect')
            ->assertOk()->json('data.authorization_url');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return ['url' => $url, 'query' => $query];
    }

    private function oauthReturn(array $query, ?User $as = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($as ?? $this->user, 'web')->get('/oauth/instagram/callback?'.http_build_query($query));
    }

    /** Faux Instagram, enregistré UNE fois ; réponses remplaçables. */
    private function fakeInstagram(array $overrides = []): void
    {
        $this->ig = $overrides + [
            'short' => [['data' => [['access_token' => self::IG_SHORT_TOKEN, 'user_id' => self::IG_USER_ID, 'permissions' => 'instagram_business_basic,instagram_business_content_publish']]], 200],
            'long' => [['access_token' => self::IG_TOKEN, 'token_type' => 'bearer', 'expires_in' => 5183944], 200],
            'refresh' => [['access_token' => 'IGAA-refreshed-token', 'token_type' => 'bearer', 'expires_in' => 5183944], 200],
            'me' => [['id' => '2900000000000001', 'user_id' => self::IG_USER_ID, 'username' => 'boulangerie.ada', 'name' => 'Boulangerie Ada', 'account_type' => 'BUSINESS', 'profile_picture_url' => 'https://scontent.exemple.test/ig.jpg'], 200],
        ];
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (Request $request) {
            $url = $request->url();
            $key = match (true) {
                $url === 'https://api.instagram.com/oauth/access_token' => 'short',
                str_starts_with($url, 'https://graph.instagram.com/access_token') => 'long',
                str_starts_with($url, 'https://graph.instagram.com/refresh_access_token') => 'refresh',
                str_starts_with($url, self::IG_GRAPH.'me') => 'me',
                default => null,
            };

            return $key === null ? null : Http::response(...$this->ig[$key]);
        });
    }

    public function test_connect_returns_the_official_url_with_state_and_professional_scopes(): void
    {
        ['url' => $url, 'query' => $q] = $this->startConnect();

        $this->assertStringStartsWith('https://www.instagram.com/oauth/authorize?', $url);
        $this->assertSame('code', $q['response_type']);
        $this->assertSame('987654321098765', $q['client_id']);
        $this->assertSame('https://tunnel.exemple.test/oauth/instagram/callback', $q['redirect_uri']);
        $this->assertSame('instagram_business_basic,instagram_business_content_publish,instagram_business_manage_insights', $q['scope']);
        $this->assertSame(64, strlen($q['state']));
        $this->assertStringNotContainsString(self::IG_SECRET, $url);
        Http::assertNothingSent();
    }

    public function test_connect_explains_missing_credentials(): void
    {
        config(['services.instagram.client_secret' => null]);
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/social/instagram/connect')
            ->assertStatus(409)->assertJsonPath('message', 'La connexion Instagram n\'est pas configurée sur ce serveur (identifiants de l\'app Instagram absents).');
    }

    public function test_valid_callback_connects_the_professional_account_with_a_long_lived_encrypted_token(): void
    {
        $this->fakeInstagram();
        $state = $this->startConnect()['query']['state'];

        // Instagram ajoute parfois « #_ » au code : il est retiré avant l'échange.
        $this->oauthReturn(['state' => $state, 'code' => 'code-abc#_'])->assertRedirect(self::BACK.'connected');

        $account = SocialAccount::sole();
        $this->assertSame('instagram', $account->platform->key);
        $this->assertSame(self::IG_USER_ID, $account->external_account_id);
        $this->assertSame('business', $account->account_type->value);
        $this->assertSame('Boulangerie Ada', $account->display_name);
        $this->assertSame('@boulangerie.ada', $account->username);

        $raw = DB::table('social_tokens')->sole();
        $this->assertSame('user', $raw->token_kind);
        $this->assertStringStartsWith('v1:', $raw->access_token_encrypted);
        $this->assertSame(self::IG_TOKEN, SocialToken::sole()->access_token_encrypted, 'Jeton LONGUE durée conservé, jamais le jeton court.');
        $this->assertEqualsWithDelta(now()->addSeconds(5183944)->getTimestamp(), SocialToken::sole()->expires_at->getTimestamp(), 5);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'instagram_business_content_publish', 'status' => 'granted', 'is_required' => true]);
        $this->assertDatabaseHas('social_permissions', ['social_account_id' => $account->id, 'scope' => 'instagram_business_manage_insights', 'status' => 'missing', 'is_required' => false]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_CONNECTED', 'user_id' => $this->user->id]);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.instagram.com/oauth/access_token' && $r->isForm()
            && $r['code'] === 'code-abc' && $r['grant_type'] === 'authorization_code' && $r['client_secret'] === self::IG_SECRET);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://graph.instagram.com/access_token') && ($r->data()['grant_type'] ?? null) === 'ig_exchange_token');
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::IG_GRAPH.'me') && $r->hasHeader('Authorization', 'Bearer '.self::IG_TOKEN));
    }

    public function test_a_personal_account_or_a_declined_publishing_permission_connects_nothing(): void
    {
        $this->fakeInstagram(['me' => [['user_id' => self::IG_USER_ID, 'username' => 'perso', 'account_type' => 'PERSONAL'], 200]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=NOT_PROFESSIONAL_ACCOUNT');

        $this->fakeInstagram(['short' => [['access_token' => self::IG_SHORT_TOKEN, 'user_id' => self::IG_USER_ID, 'permissions' => ['instagram_business_basic']], 200]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=PUBLISH_SCOPE_DENIED');

        $this->assertSame(0, SocialAccount::count());
        $this->assertSame(0, SocialToken::count());
    }

    public function test_reconnecting_updates_the_same_account_and_replaces_the_token(): void
    {
        $this->fakeInstagram();
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c1'])->assertRedirect(self::BACK.'connected');
        $this->fakeInstagram(['long' => [['access_token' => 'IGAA-second-token', 'expires_in' => 5183944], 200]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c2'])->assertRedirect(self::BACK.'connected');

        $this->assertSame(1, SocialAccount::count());
        $this->assertSame('IGAA-second-token', SocialToken::where('status', 'active')->sole()->access_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'OAUTH_RECONNECTED']);
    }

    public function test_errors_and_invalid_state_connect_nothing(): void
    {
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'error' => 'access_denied', 'error_reason' => 'user_denied'])
            ->assertRedirect(self::BACK.'error&code=ACCESS_DENIED');
        $this->oauthReturn(['state' => 'inconnu', 'code' => 'c'])->assertRedirect(self::BACK.'error&code=INVALID_STATE');
        Http::assertNothingSent();

        $this->fakeInstagram(['short' => [['error_type' => 'OAuthException', 'code' => 400, 'error_message' => 'Invalid platform app'], 400]]);
        $this->oauthReturn(['state' => $this->startConnect()['query']['state'], 'code' => 'c'])->assertRedirect(self::BACK.'error&code=OAUTH_CLIENT_INVALID');
        $this->assertSame(0, SocialAccount::count());
    }

    public function test_a_token_close_to_expiry_is_renewed_once_and_a_fresh_one_is_left_alone(): void
    {
        $this->fakeInstagram();
        $account = $this->instagramAccount($this->user, ['expires_at' => now()->addDays(5)]);
        SocialToken::query()->update(['created_at' => now()->subDays(55)]);

        $this->assertSame('IGAA-refreshed-token', app(InstagramTokenManager::class)->accessTokenFor($account));
        $token = SocialToken::sole();
        $this->assertSame('IGAA-refreshed-token', $token->access_token_encrypted);
        $this->assertEqualsWithDelta(now()->addSeconds(5183944)->getTimestamp(), $token->expires_at->getTimestamp(), 5);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TOKEN_REFRESHED']);

        // Jeton récent (moins de 24 h) ou lointain : aucun renouvellement.
        $this->assertSame('IGAA-refreshed-token', app(InstagramTokenManager::class)->accessTokenFor($account->fresh()));
        Http::assertSentCount(1);
    }

    public function test_an_expired_token_requires_reconnection_without_any_call(): void
    {
        $account = $this->instagramAccount($this->user, ['expires_at' => now()->subHour()]);
        $this->fakeInstagram();

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")->assertStatus(409);

        $this->assertSame('expired', $account->fresh()->status->value);
        Http::assertNothingSent();
    }

    public function test_sync_rereads_the_profile(): void
    {
        $account = $this->instagramAccount($this->user);
        $this->fakeInstagram(['me' => [['user_id' => self::IG_USER_ID, 'username' => 'ada.boulangerie', 'name' => 'Ada & Fils', 'account_type' => 'MEDIA_CREATOR'], 200]]);

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/social/accounts/{$account->id}/sync")
            ->assertOk()->assertJsonPath('message', 'Compte synchronisé.');
        $this->assertSame('Ada & Fils', $account->fresh()->display_name);
        $this->assertSame('@ada.boulangerie', $account->fresh()->username);
    }
}
