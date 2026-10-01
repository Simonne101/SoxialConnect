<?php

namespace Tests\Feature\Social;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\YouTubeTokenManager;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/** Jeton d'accès YouTube : réutilisation, refresh chiffré, refus de Google sans boucle. */
class YouTubeTokenManagerTest extends TestCase
{
    use RefreshDatabase, YouTubeFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function manager(): YouTubeTokenManager
    {
        return app(YouTubeTokenManager::class);
    }

    public function test_a_valid_token_is_reused_without_calling_google(): void
    {
        $account = $this->youtubeAccount(User::factory()->create());

        $this->assertSame(self::ACCESS_TOKEN, $this->manager()->accessTokenFor($account));
        Http::assertNothingSent();
    }

    public function test_an_expiring_token_is_refreshed_and_stored_encrypted(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.refreshed', 'expires_in' => 3599, 'scope' => 'x'])]);
        $account = $this->youtubeAccount(User::factory()->create(), ['expires_at' => now()->addMinute()]);

        $this->assertSame('ya29.refreshed', $this->manager()->accessTokenFor($account));

        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === self::REFRESH_TOKEN);
        $token = SocialToken::sole();
        $this->assertSame('ya29.refreshed', $token->access_token_encrypted);
        $this->assertSame(self::REFRESH_TOKEN, $token->refresh_token_encrypted); // conservé
        $this->assertTrue($token->expires_at->isAfter(now()->addMinutes(50)));
        $this->assertNotNull($token->last_refreshed_at);
        $this->assertStringStartsWith('v1:', DB::table('social_tokens')->value('access_token_encrypted'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'TOKEN_REFRESHED', 'subject_id' => $account->id]);

        // Le jeton neuf est ensuite réutilisé.
        $this->manager()->accessTokenFor($account->fresh());
        Http::assertSentCount(1);
    }

    public function test_refused_refresh_marks_the_account_for_reconnection_without_looping(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
        $account = $this->youtubeAccount(User::factory()->create(), ['expires_at' => now()->subMinute()]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertTrue($e->requiresReconnect);
            $this->assertSame('TOKEN_REVOKED', $e->errorCode);
            $this->assertStringNotContainsString(self::REFRESH_TOKEN, $e->getMessage());
        }

        $this->assertSame('expired', $account->fresh()->status->value);
        $this->assertSame('invalid', SocialToken::sole()->status->value);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TOKEN_REFRESH_FAILED', 'subject_id' => $account->id]);

        // Plus aucun appel à Google tant que l'utilisateur ne s'est pas reconnecté.
        try {
            $this->manager()->accessTokenFor($account->fresh());
        } catch (OAuthException $e) {
            $this->assertSame('ACCOUNT_DISCONNECTED', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_google_outage_during_refresh_is_transient_and_keeps_the_account_connected(): void
    {
        Http::fake(['oauth2.googleapis.com/token' => Http::response('', 503)]);
        $account = $this->youtubeAccount(User::factory()->create(), ['expires_at' => now()->subMinute()]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertTrue($e->transient);
        }
        $this->assertSame('connected', $account->fresh()->status->value);
        $this->assertSame('active', SocialToken::sole()->status->value);
    }
}
