<?php

namespace Tests\Feature\Social;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Social\Concerns\TikTokFixtures;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Page « Réseaux sociaux » : un utilisateur connecté obtient TOUJOURS une réponse normale, même
 * sans aucun compte social et même avant la vérification de son adresse e-mail (régression :
 * 403 → « Impossible de charger ces données » pour chaque nouveau compte). Connecter un réseau
 * reste réservé aux adresses vérifiées (docs/security.md §3).
 */
class SocialAccountsAccessTest extends TestCase
{
    use RefreshDatabase, TikTokFixtures, YouTubeFixtures;

    public function test_a_newly_registered_user_gets_an_empty_list_not_an_error(): void
    {
        $email = 'nouveau@exemple.test';
        $this->fromSpa()->postJson('/api/v1/auth/register', [
            'name' => 'Nouvel utilisateur',
            'email' => $email,
            'password' => 'Mot-de-passe-solide-2026',
            'password_confirmation' => 'Mot-de-passe-solide-2026',
        ])->assertCreated();
        $this->assertNull(User::where('email', $email)->value('email_verified_at'));

        // Même session que l'inscription : exactement le parcours de la SPA.
        $this->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->assertExactJson(['data' => [], 'message' => null, 'meta' => []]);
        $this->fromSpa()->getJson('/api/v1/social/platforms')->assertOk();
    }

    public function test_verified_user_without_any_account_gets_an_empty_list(): void
    {
        $this->actingAs(User::factory()->create())->fromSpa()->getJson('/api/v1/social/accounts')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_user_with_youtube_and_tiktok_sees_both_without_tokens(): void
    {
        $user = User::factory()->create();
        $this->youtubeAccount($user);
        $this->tiktokAccount($user);

        $json = $this->actingAs($user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['platform' => 'youtube', 'status' => 'connected'])
            ->assertJsonFragment(['platform' => 'tiktok', 'status' => 'connected'])
            ->getContent();
        foreach ([self::ACCESS_TOKEN, self::TT_ACCESS, 'access_token', 'refresh_token'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
    }

    public function test_unverified_user_can_list_but_cannot_connect_a_network(): void
    {
        $user = User::factory()->unverified()->create();
        $this->configureYouTube();
        $this->configureTikTok();

        $this->actingAs($user)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->assertJsonCount(0, 'data');
        foreach (['youtube', 'tiktok'] as $provider) {
            $this->actingAs($user)->fromSpa()->postJson("/api/v1/social/{$provider}/connect")
                ->assertForbidden()->assertJsonPath('message', 'Vérifiez votre adresse e-mail pour accéder à cette fonctionnalité.');
        }
    }

    public function test_accounts_of_other_users_never_appear_and_guests_are_refused(): void
    {
        $owner = User::factory()->create();
        $this->youtubeAccount($owner);
        $other = User::factory()->unverified()->create();

        $this->actingAs($other)->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame(1, SocialAccount::count());

        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/v1/social/accounts')->assertUnauthorized();
    }
}
