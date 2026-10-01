<?php

namespace Tests\Feature\Social;

use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\Tokens\TikTokTokenManager;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Social\Concerns\TikTokFixtures;
use Tests\TestCase;

/** Jeton TikTok : réutilisation, refresh avec rotation du refresh token, expiration, reconnexion. */
class TikTokTokenManagerTest extends TestCase
{
    use RefreshDatabase, TikTokFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function manager(): TikTokTokenManager
    {
        return app(TikTokTokenManager::class);
    }

    public function test_a_valid_token_is_reused_without_calling_tiktok(): void
    {
        $account = $this->tiktokAccount(User::factory()->create());
        $this->assertSame(self::TT_ACCESS, $this->manager()->accessTokenFor($account));
        Http::assertNothingSent();
    }

    public function test_expiring_token_is_refreshed_and_the_rotated_refresh_token_replaces_the_old_one(): void
    {
        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response([
            'access_token' => 'act.nouveau', 'expires_in' => 86400, 'open_id' => self::TT_OPEN_ID,
            'refresh_token' => 'rft.nouveau', 'refresh_expires_in' => 31536000, 'scope' => 'user.info.basic,video.publish',
        ])]);
        $account = $this->tiktokAccount(User::factory()->create(), ['expires_at' => now()->addMinutes(5)]);

        $this->assertSame('act.nouveau', $this->manager()->accessTokenFor($account));
        Http::assertSent(fn (Request $r) => $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === self::TT_REFRESH && $r['client_key'] === 'test-client-key');

        $token = SocialToken::sole();
        $this->assertSame('act.nouveau', $token->access_token_encrypted);
        $this->assertSame('rft.nouveau', $token->refresh_token_encrypted);
        $this->assertTrue($token->expires_at->isAfter(now()->addHours(23)));
        $this->assertTrue($token->refresh_expires_at->isAfter(now()->addDays(360)));
        $raw = DB::table('social_tokens')->sole();
        $this->assertStringNotContainsString('rft.nouveau', $raw->refresh_token_encrypted);
        $this->assertStringNotContainsString(self::TT_REFRESH, (string) $raw->refresh_token_encrypted);
        $this->assertDatabaseHas('audit_logs', ['action' => 'TOKEN_REFRESHED', 'subject_id' => $account->id]);
    }

    public function test_expired_refresh_token_requires_reconnection_without_calling_tiktok(): void
    {
        $account = $this->tiktokAccount(User::factory()->create(), ['expires_at' => now()->subMinute(), 'refresh_expires_at' => now()->subDay()]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertTrue($e->requiresReconnect);
            $this->assertSame('REFRESH_TOKEN_EXPIRED', $e->errorCode);
        }
        Http::assertNothingSent();
        $this->assertSame('expired', $account->fresh()->status->value);
    }

    public function test_refused_refresh_marks_the_account_expired_without_looping(): void
    {
        Http::fake(['open.tiktokapis.com/v2/oauth/token/' => Http::response(['error' => 'invalid_grant', 'error_description' => 'x', 'log_id' => 'y'])]);
        $account = $this->tiktokAccount(User::factory()->create(), ['expires_at' => now()->subMinute()]);

        try {
            $this->manager()->accessTokenFor($account);
            $this->fail('Une OAuthException était attendue.');
        } catch (OAuthException $e) {
            $this->assertSame('TOKEN_REVOKED', $e->errorCode);
            $this->assertStringNotContainsString(self::TT_REFRESH, $e->getMessage());
        }
        $this->assertSame('expired', $account->fresh()->status->value);
        $this->assertSame('invalid', SocialToken::sole()->status->value);

        try {
            $this->manager()->accessTokenFor($account->fresh());
        } catch (OAuthException $e) {
            $this->assertSame('ACCOUNT_DISCONNECTED', $e->errorCode);
        }
        Http::assertSentCount(1);
    }
}
