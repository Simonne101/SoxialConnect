<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Login / Logout / utilisateur courant par session Sanctum (SPA). */
class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge(['email' => 'marie@example.com', 'password' => 'une-phrase-de-passe-longue'], $attributes));
    }

    public function test_login_with_valid_credentials_returns_user(): void
    {
        $user = $this->user();

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'MARIE@example.com', 'password' => 'une-phrase-de-passe-longue'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('message', 'Connexion réussie.');

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'LOGIN']);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_login_with_wrong_password_returns_generic_422(): void
    {
        $this->user();

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => 'mauvais-mot-de-passe'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Adresse e-mail ou mot de passe incorrect.');

        $this->assertGuest();
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN_FAILED']);
    }

    public function test_unknown_email_gets_the_same_message_as_wrong_password(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'inconnu@example.com', 'password' => 'peu-importe-1234'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Adresse e-mail ou mot de passe incorrect.');
    }

    public function test_suspended_user_cannot_login(): void
    {
        $this->user(['status' => 'suspended']);

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => 'une-phrase-de-passe-longue'])
            ->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_login_is_rate_limited_after_five_attempts(): void
    {
        $this->user();
        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => 'faux-'.$i]);
        }

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => 'une-phrase-de-passe-longue'])
            ->assertStatus(429);
        $this->assertGuest();
    }

    public function test_current_user_requires_authentication(): void
    {
        $this->fromSpa()->getJson('/api/v1/users/me')
            ->assertUnauthorized()
            ->assertExactJson(['message' => 'Vous devez être connecté.', 'errors' => []]);
    }

    public function test_current_user_is_returned_without_secrets(): void
    {
        $user = $this->user();

        $this->actingAs($user)->fromSpa()->getJson('/api/v1/users/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'marie@example.com')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.two_factor_secret');
    }

    public function test_logout_ends_session_with_204(): void
    {
        $user = $this->user();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'marie@example.com', 'password' => 'une-phrase-de-passe-longue'])->assertOk();

        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->assertGuest('web');
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'LOGOUT']);
    }

    public function test_update_profile_validates_and_saves(): void
    {
        $user = $this->user();

        $this->actingAs($user)->fromSpa()->patchJson('/api/v1/users/me', ['timezone' => 'Mars/Olympus'])
            ->assertUnprocessable()->assertJsonValidationErrors(['timezone']);

        $this->actingAs($user)->fromSpa()->patchJson('/api/v1/users/me', [
            'name' => 'Marie D.',
            'headline' => 'Créatrice de contenu',
            'email' => 'pirate@example.com', // ignoré : non modifiable par cette route
        ])->assertOk()->assertJsonPath('data.name', 'Marie D.')->assertJsonPath('data.email', 'marie@example.com');
    }

    public function test_forgot_password_does_not_reveal_whether_account_exists(): void
    {
        $this->user();

        $known = $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'marie@example.com']);
        $unknown = $this->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@example.com']);

        $known->assertOk();
        $unknown->assertOk();
        $this->assertSame($known->json('message'), $unknown->json('message'));
    }
}
