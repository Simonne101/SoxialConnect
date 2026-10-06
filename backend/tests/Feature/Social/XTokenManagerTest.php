<?php

namespace Tests\Feature\Social;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\XTokenManager;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\XFixtures;
use Tests\TestCase;

/** Jeton X : réutilisation, refresh avec rotation du refresh token, refus, absence de refresh token. */
class XTokenManagerTest extends TestCase
{
    use RefreshDatabase, XFixtures;

    private const TOKEN_URL = 'https://api.x.com/2/oauth2/token';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function manager(): XTokenManager
    {
        return app(XTokenManager::class);
    }

    public function test_a_valid_token_is_reused_without_calling_x(): void
    {
        $account = $this->xAccount(User::factory()->create());
        $this->assertSame(self::X_ACCESS, $this->manager()->accessTokenFor($account));
        Http::assertNothingSent();
    }

    public function test_expiring_token_is_refreshed_and_the_rotated_refresh_token_replaces_the_old_one(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([
            'token_type' => 'bearer', 'expires_in' => 7200, 'access_token' => 'x-access-nouveau',
            'scope' => 'tweet.write users.read tweet.read offline.access', 'refresh_token' => 'x-refresh-nouveau',
        ])]);
        $account = $this->xAccount(User::factory()->create(), ['expires_at' => now()->addMinutes(5)]);

        $this->assertSame('x-access-nouveau', $this->manager()->accessTokenFor($account));
        Http::assertSent(fn (Request $r) => $r->isForm() && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === self::X_REFRESH
            && $r->hasHeader('Authorization', 'Basic '.base64_encode(self::X_CLIENT_ID.':'.self::X_CLIENT_SECRET)));

        $token = SocialToken::sole();
        $this->assertSame('x-access-nouveau', $token->access_token_encrypted);
        $this->assertSame('x-refresh-nouveau', $token->refresh_token_encrypted);
        $this->assertTrue($token->expires_at->isAfter(now()->addMinutes(110)));
        $raw = DB::table('social_tokens')->sole();
        $this->assertStringNotContainsString('x-refresh-nouveau', $raw->refresh_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TOKEN_REFRESHED', 'subject_id' => $account->id]);
    }

    public function test_refused_refresh_marks_the_account_expired_without_looping(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_request', 'error_description' => 'Value passed for the token was invalid.'], 400)]);
        $account = $this->xAccount(User::factory()->create(), ['expires_at' => now()->subMinute()]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertSame('TOKEN_REVOKED', $e->errorCode);
            $this->assertTrue($e->requiresReconnect);
            $this->assertStringNotContainsString(self::X_REFRESH, $e->getMessage());
        }
        $this->assertSame('expired', $account->fresh()->status->value);
        $this->assertSame('invalid', SocialToken::sole()->status->value);
        $this->assertSame(self::X_REFRESH, SocialToken::sole()->refresh_token_encrypted, 'Aucun token effacé sur un échec.');

        try {
            $this->manager()->accessTokenFor($account->fresh());
        } catch (OAuthException $e) {
            $this->assertSame('ACCOUNT_DISCONNECTED', $e->errorCode);
        }
        Http::assertSentCount(1);
    }

    public function test_temporary_failure_keeps_the_tokens_and_the_account_connected(): void
    {
        Http::fake([self::TOKEN_URL => Http::response([], 503)]);
        $account = $this->xAccount(User::factory()->create(), ['expires_at' => now()->subMinute()]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertTrue($e->transient);
        }
        $this->assertSame('connected', $account->fresh()->status->value);
        $this->assertSame('active', SocialToken::sole()->status->value);
        $this->assertSame(self::X_REFRESH, SocialToken::sole()->refresh_token_encrypted);
    }

    public function test_missing_refresh_token_requires_reconnection_without_calling_x(): void
    {
        $account = $this->xAccount(User::factory()->create(), ['expires_at' => now()->subMinute(), 'refresh_token_encrypted' => null]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertSame('REFRESH_TOKEN_MISSING', $e->errorCode);
        }
        Http::assertNothingSent();
        $this->assertSame('expired', $account->fresh()->status->value);
    }
}
