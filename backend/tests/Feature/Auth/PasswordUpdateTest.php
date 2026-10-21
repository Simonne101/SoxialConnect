<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changement de mot de passe depuis la SPA (Paramètres › Sécurité) : PUT /api/v1/auth/password
 * (Fortify updatePasswords, session Sanctum). Contrat utilisé par frontend/src/services/misc.ts.
 */
class PasswordUpdateTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT = 'Mot-de-passe-actuel-2026';

    private const NEXT = 'Nouveau-mot-de-passe-2026';

    private function user(): User
    {
        return User::factory()->create(['email' => 'secu@exemple.test', 'password' => self::CURRENT]);
    }

    private function change(array $payload)
    {
        return $this->fromSpa()->putJson('/api/v1/auth/password', $payload);
    }

    public function test_password_is_changed_and_the_session_stays_open(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');

        $this->change(['current_password' => self::CURRENT, 'password' => self::NEXT, 'password_confirmation' => self::NEXT])
            ->assertOk();

        $this->assertTrue(Hash::check(self::NEXT, $user->fresh()->password));
        $this->assertFalse(Hash::check(self::CURRENT, $user->fresh()->password));
        $this->assertDatabaseHas('audit_logs', ['action' => 'PASSWORD_CHANGED', 'user_id' => $user->id]);
        // L'utilisateur n'est pas déconnecté par le changement.
        $this->fromSpa()->getJson('/api/v1/users/me')->assertOk();
    }

    public function test_new_password_works_for_login_and_the_old_one_no_longer(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');
        $this->change(['current_password' => self::CURRENT, 'password' => self::NEXT, 'password_confirmation' => self::NEXT])->assertOk();
        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'secu@exemple.test', 'password' => self::CURRENT])->assertUnprocessable();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'secu@exemple.test', 'password' => self::NEXT])->assertOk();
    }

    public function test_wrong_current_password_is_refused_and_nothing_changes(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');

        $this->change(['current_password' => 'Pas-le-bon-mot-de-passe', 'password' => self::NEXT, 'password_confirmation' => self::NEXT])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['current_password'])
            ->assertJsonMissingValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
        $this->assertDatabaseMissing('audit_logs', ['action' => 'PASSWORD_CHANGED']);
    }

    public function test_new_password_must_follow_the_password_policy(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');

        $this->change(['current_password' => self::CURRENT, 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
        $this->change(['current_password' => self::CURRENT, 'password' => '', 'password_confirmation' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_confirmation_must_match(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');

        $response = $this->change(['current_password' => self::CURRENT, 'password' => self::NEXT, 'password_confirmation' => 'Autre-mot-de-passe-2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);

        // Message de confirmation (le frontend le place sous le champ « Confirmer »).
        $this->assertMatchesRegularExpression('/confirm/i', $response->json('errors.password.0'));
        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_unauthenticated_request_gets_a_json_401(): void
    {
        $user = $this->user();

        $this->change(['current_password' => self::CURRENT, 'password' => self::NEXT, 'password_confirmation' => self::NEXT])
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Vous devez être connecté.')
            ->assertHeaderMissing('Location');

        $this->assertTrue(Hash::check(self::CURRENT, $user->fresh()->password));
    }

    public function test_the_password_never_appears_in_the_response(): void
    {
        $user = $this->user();
        $this->actingAs($user, 'web');

        $content = $this->change(['current_password' => 'Pas-le-bon-mot-de-passe', 'password' => self::NEXT, 'password_confirmation' => self::NEXT])->getContent();

        foreach (['Pas-le-bon-mot-de-passe', self::NEXT, self::CURRENT] as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }
}
