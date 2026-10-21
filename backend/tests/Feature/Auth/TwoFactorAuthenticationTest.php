<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Double authentification TOTP (étape 8, lot 5) : gestion derrière la confirmation du mot de passe, activation
 * confirmée par un premier code, défi à la connexion (code TOTP non rejouable ou code de secours à usage unique),
 * secrets jamais exposés ni stockés en clair.
 */
class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'une-phrase-de-passe-longue';

    private function user(): User
    {
        return User::factory()->create(['email' => 'marie@example.com', 'password' => self::PASSWORD]);
    }

    /** Code TOTP d'une période donnée (0 = actuelle, 1 = suivante, toutes deux acceptées par la fenêtre). */
    private function otp(User $user, int $period = 0): string
    {
        $engine = new Google2FA;

        return $engine->oathTotp(decrypt($user->fresh()->two_factor_secret), $engine->getTimestamp() + $period);
    }

    private function confirmPassword(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/user/confirm-password', ['password' => self::PASSWORD])->assertCreated();
    }

    /** Active et confirme la 2FA ; renvoie l'utilisateur rechargé. */
    private function enableTwoFactor(User $user): User
    {
        $this->actingAs($user);
        $this->confirmPassword();
        $this->fromSpa()->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();
        $this->fromSpa()->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => $this->otp($user)])->assertOk();

        return $user->fresh();
    }

    private function logoutEverywhere(): void
    {
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    public function test_managing_two_factor_requires_a_recent_password_confirmation(): void
    {
        $this->actingAs($this->user());

        foreach ([['post', 'user/two-factor-authentication'], ['delete', 'user/two-factor-authentication'], ['get', 'user/two-factor-qr-code'],
            ['get', 'user/two-factor-secret-key'], ['get', 'user/two-factor-recovery-codes'], ['post', 'user/two-factor-recovery-codes']] as [$method, $path]) {
            $this->fromSpa()->json($method, '/api/v1/auth/'.$path)->assertStatus(423);
        }

        $this->fromSpa()->postJson('/api/v1/auth/user/confirm-password', ['password' => 'mauvais-mot-de-passe'])->assertUnprocessable();
        $this->fromSpa()->getJson('/api/v1/auth/user/confirmed-password-status')->assertJsonPath('confirmed', false);
        $this->confirmPassword();
        $this->fromSpa()->getJson('/api/v1/auth/user/confirmed-password-status')->assertJsonPath('confirmed', true);
        $this->fromSpa()->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();
    }

    public function test_activation_is_only_effective_after_a_valid_first_code(): void
    {
        $user = $this->user();
        $this->actingAs($user);
        $this->confirmPassword();
        $this->fromSpa()->postJson('/api/v1/auth/user/two-factor-authentication')->assertOk();

        $qr = $this->fromSpa()->getJson('/api/v1/auth/user/two-factor-qr-code')->assertOk();
        $this->assertStringStartsWith('<svg', (string) $qr->json('svg'));
        $this->assertStringStartsWith('otpauth://totp/', (string) $qr->json('url'));
        $this->fromSpa()->getJson('/api/v1/users/me')->assertJsonPath('data.two_factor_enabled', false);

        $this->fromSpa()->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => '000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        $this->fromSpa()->postJson('/api/v1/auth/user/confirmed-two-factor-authentication', ['code' => $this->otp($user)])->assertOk();
        $this->fromSpa()->getJson('/api/v1/users/me')->assertJsonPath('data.two_factor_enabled', true)
            ->assertJsonMissingPath('data.two_factor_secret')->assertJsonMissingPath('data.two_factor_recovery_codes');
    }

    public function test_login_then_requires_the_second_factor_and_a_code_cannot_be_replayed(): void
    {
        $user = $this->enableTwoFactor($this->user());
        $used = $this->otp($user); // code de la confirmation
        $this->logoutEverywhere();

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => self::PASSWORD])
            ->assertOk()->assertExactJson(['two_factor' => true]);
        $this->assertGuest('web');

        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertUnprocessable();
        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['code' => $used])->assertUnprocessable();
        $this->assertGuest('web');

        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['code' => $this->otp($user, 1)])
            ->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonPath('data.two_factor_enabled', true);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_recovery_code_works_exactly_once(): void
    {
        $user = $this->enableTwoFactor($this->user());
        $recovery = $user->recoveryCodes()[0];
        $this->logoutEverywhere();

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => self::PASSWORD])->assertJsonPath('two_factor', true);
        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $recovery])->assertOk();
        $this->assertNotContains($recovery, $user->fresh()->recoveryCodes());

        $this->logoutEverywhere();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => self::PASSWORD])->assertJsonPath('two_factor', true);
        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['recovery_code' => $recovery])->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_the_challenge_needs_a_pending_login_and_is_rate_limited(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['code' => '123456'])->assertUnprocessable(); // aucun mot de passe validé

        $this->enableTwoFactor($this->user());
        $this->logoutEverywhere();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => self::PASSWORD])->assertJsonPath('two_factor', true);
        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['code' => sprintf('%06d', $i)]);
        }

        $this->fromSpa()->postJson('/api/v1/auth/two-factor-challenge', ['code' => '999999'])->assertStatus(429);
        $this->assertGuest('web');
    }

    public function test_secrets_are_encrypted_at_rest_and_disabling_requires_confirmation(): void
    {
        $user = $this->enableTwoFactor($this->user());
        $secret = decrypt($user->two_factor_secret);
        $raw = DB::table('users')->where('id', $user->id)->first(['two_factor_secret', 'two_factor_recovery_codes']);
        $this->assertStringNotContainsString($secret, (string) $raw->two_factor_secret);
        $this->assertStringNotContainsString($user->recoveryCodes()[0], (string) $raw->two_factor_recovery_codes);

        $this->travel(4)->hours(); // confirmation expirée (3 h)
        $this->fromSpa()->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertStatus(423);
        $this->confirmPassword();
        $this->fromSpa()->deleteJson('/api/v1/auth/user/two-factor-authentication')->assertOk();

        $fresh = $user->fresh();
        $this->assertNull($fresh->two_factor_secret);
        $this->assertNull($fresh->two_factor_confirmed_at);
    }
}
