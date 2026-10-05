<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Contrat des URL d'authentification appelées par le frontend (src/services/auth.ts, src/lib/http.ts).
 * Toute modification de ces chemins côté Laravel doit casser ce test plutôt que l'application
 * (régression : « Réponse inattendue du serveur (erreur 404) » à l'inscription / connexion).
 */
class AuthApiContractTest extends TestCase
{
    use RefreshDatabase;

    /** Méthode + URI exactement comme le frontend les appelle. */
    private const FRONTEND_CALLS = [
        ['GET', 'sanctum/csrf-cookie'],
        ['POST', 'api/v1/auth/login'],
        ['POST', 'api/v1/auth/register'],
        ['POST', 'api/v1/auth/logout'],
        ['POST', 'api/v1/auth/forgot-password'],
        ['POST', 'api/v1/auth/email/verification-notification'],
        ['GET', 'api/v1/users/me'],
        ['PATCH', 'api/v1/users/me'],
    ];

    public function test_every_url_called_by_the_frontend_exists_in_laravel(): void
    {
        $routes = Route::getRoutes();
        foreach (self::FRONTEND_CALLS as [$method, $uri]) {
            $route = collect($routes->getRoutes())->first(fn ($r) => $r->uri() === $uri && in_array($method, $r->methods(), true));
            $this->assertNotNull($route, "Route attendue par le frontend introuvable : {$method} /{$uri}");
        }
    }

    public function test_unknown_api_paths_answer_json_never_html(): void
    {
        // Un 404 de l'API reste du JSON (message lisible) : un 404 non JSON vient donc d'un autre serveur.
        $this->fromSpa()->postJson('/api/v1/auth/inexistant')->assertNotFound()
            ->assertJsonPath('message', 'Ressource introuvable.');
    }

    public function test_login_logout_and_relogin_through_the_spa_session(): void
    {
        $user = User::factory()->create(['email' => 'connexion@exemple.test', 'password' => 'Mot-de-passe-solide-2026']);

        $this->fromSpa()->getJson('/sanctum/csrf-cookie')->assertNoContent();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'connexion@exemple.test', 'password' => 'Mot-de-passe-solide-2026'])
            ->assertOk()->assertJsonPath('data.email', 'connexion@exemple.test');
        $this->assertAuthenticatedAs($user);
        $this->fromSpa()->getJson('/api/v1/users/me')->assertOk();
        $this->fromSpa()->getJson('/api/v1/dashboard/summary')->assertOk(); // route protégée (adresse vérifiée)

        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->getJson('/api/v1/users/me')->assertUnauthorized();

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'connexion@exemple.test', 'password' => 'Mot-de-passe-solide-2026'])->assertOk();
    }

    public function test_already_logged_in_user_gets_a_json_409_never_a_redirect_to_the_root(): void
    {
        // Régression : « Réponse inattendue du serveur (erreur 404, POST /) ». Le middleware « guest »
        // renvoyait 302 → « / » ; le navigateur suivait la redirection jusqu'à Vite, qui répondait 404.
        Notification::fake();
        $this->fromSpa()->postJson('/api/v1/auth/register', [
            'name' => 'Déjà connecté', 'email' => 'deja@exemple.test',
            'password' => 'Mot-de-passe-solide-2026', 'password_confirmation' => 'Mot-de-passe-solide-2026',
        ])->assertCreated();

        $calls = [
            ['/api/v1/auth/login', ['email' => 'deja@exemple.test', 'password' => 'Mot-de-passe-solide-2026']],
            ['/api/v1/auth/register', ['name' => 'Autre', 'email' => 'autre@exemple.test', 'password' => 'Mot-de-passe-solide-2026', 'password_confirmation' => 'Mot-de-passe-solide-2026']],
            ['/api/v1/auth/forgot-password', ['email' => 'deja@exemple.test']],
        ];
        foreach ($calls as [$uri, $payload]) {
            $this->fromSpa()->postJson($uri, $payload)
                ->assertStatus(409)
                ->assertHeaderMissing('Location')
                ->assertJsonPath('message', 'Vous êtes déjà connecté. Déconnectez-vous pour utiliser un autre compte.');
        }
        $this->assertSame(1, User::count(), 'Aucun second compte créé pendant une session ouverte.');

        // Après déconnexion, la connexion fonctionne à nouveau normalement.
        $this->fromSpa()->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'deja@exemple.test', 'password' => 'Mot-de-passe-solide-2026'])->assertOk();
    }

    public function test_guest_middleware_keeps_laravel_redirect_for_non_json_requests(): void
    {
        // Comportement web inchangé : seule une requête JSON reçoit la réponse 409.
        $this->actingAs(User::factory()->create(), 'web')
            ->post('/api/v1/auth/login', ['email' => 'x@exemple.test', 'password' => 'x'], ['Accept' => 'text/html'])
            ->assertRedirect();
    }

    public function test_wrong_credentials_are_a_real_authentication_error_not_a_404(): void
    {
        User::factory()->create(['email' => 'connexion@exemple.test', 'password' => 'Mot-de-passe-solide-2026']);

        foreach ([['connexion@exemple.test', 'Mauvais-mot-de-passe-1'], ['inconnu@exemple.test', 'Mot-de-passe-solide-2026']] as [$email, $password]) {
            $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])
                ->assertUnprocessable()
                ->assertJsonPath('message', 'Adresse e-mail ou mot de passe incorrect.');
        }
        $this->assertGuest();
    }

    public function test_unverified_user_can_log_in_but_not_reach_verified_routes(): void
    {
        Notification::fake();
        User::factory()->unverified()->create(['email' => 'nonverifie@exemple.test', 'password' => 'Mot-de-passe-solide-2026']);

        $this->fromSpa()->postJson('/api/v1/auth/login', ['email' => 'nonverifie@exemple.test', 'password' => 'Mot-de-passe-solide-2026'])
            ->assertOk()->assertJsonPath('data.email_verified', false);
        $this->fromSpa()->getJson('/api/v1/social/accounts')->assertOk(); // page Réseaux sociaux accessible
        $this->fromSpa()->getJson('/api/v1/dashboard/summary')->assertForbidden()
            ->assertJsonPath('message', 'Vérifiez votre adresse e-mail pour accéder à cette fonctionnalité.');
    }
}
