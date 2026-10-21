<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Social\Concerns\TikTokFixtures;
use Tests\Feature\Social\Concerns\YouTubeFixtures;
use Tests\TestCase;

/**
 * Parcours complet d'inscription : compte et session créés, e-mail de vérification découplé de la
 * requête (file d'attente), lien de vérification fonctionnel, puis accès aux réseaux sociaux.
 * Régression couverte : un échec d'envoi d'e-mail renvoyait 500 alors que le compte était déjà créé.
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase, TikTokFixtures, YouTubeFixtures;

    private const PASSWORD = 'Guillo-Mot-de-passe-2026';

    private function register(array $overrides = [])
    {
        return $this->fromSpa()->postJson('/api/v1/auth/register', array_merge([
            'name' => 'Guillo',
            'email' => 'guillo@exemple.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ], $overrides));
    }

    public function test_valid_registration_creates_the_account_opens_the_session_and_queues_the_email(): void
    {
        Notification::fake();

        $this->register()->assertCreated()->assertJsonPath('data.email_verified', false);

        $user = User::sole();
        $this->assertSame('guillo@exemple.test', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->fromSpa()->getJson('/api/v1/users/me')->assertOk()->assertJsonPath('data.email', 'guillo@exemple.test');
        Notification::assertSentTo($user, VerifyEmailNotification::class, fn ($n) => $n instanceof ShouldQueue);
    }

    public function test_mail_server_failure_never_breaks_registration(): void
    {
        // Serveur SMTP injoignable + vraie file d'attente : avant correction → 500 avec compte créé.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
            'mail.mailers.smtp.timeout' => 2,
            'queue.default' => 'database',
        ]);

        $this->register()->assertCreated();

        $this->assertSame(1, User::count());
        $this->assertSame(1, DB::table('jobs')->count(), 'L\'e-mail de vérification attend dans la file, sans bloquer l\'inscription.');
        $this->fromSpa()->getJson('/api/v1/social/accounts')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_log_mailer_used_locally_does_not_block_registration(): void
    {
        config(['mail.default' => 'log']); // file « sync » des tests : l'e-mail est réellement « envoyé » dans le journal

        $this->register()->assertCreated();
        $this->assertSame(1, User::count());
    }

    public function test_the_link_from_the_email_verifies_the_address(): void
    {
        Notification::fake();
        $this->register()->assertCreated();
        $user = User::sole();

        $url = null;
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith(rtrim((string) config('app.url'), '/').'/email/verify/', (string) $url);

        // Clic sur le lien (route web signée) : 302 vers la SPA en navigation, 204 en JSON.
        $this->actingAs($user, 'web')->withHeaders(['Accept' => 'text/html'])->get((string) $url)
            ->assertRedirect(rtrim((string) config('app.frontend_url'), '/').'/dashboard?verified=1');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_link_uses_the_origin_of_the_signup_even_behind_the_https_tunnel(): void
    {
        // Inscription relayée par le proxy local (Vite) depuis le tunnel HTTPS (en-têtes ngrok).
        Notification::fake();
        $tunnel = 'https://tunnel.exemple.test';
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'tunnel.exemple.test'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'Origin' => $tunnel, 'Referer' => $tunnel.'/register'])
            ->postJson($tunnel.'/api/v1/auth/register', [
                'name' => 'Guillo', 'email' => 'guillo@exemple.test',
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
            ])->assertCreated();
        $user = User::sole();

        $url = null;
        Notification::assertSentTo($user, VerifyEmailNotification::class, function (VerifyEmailNotification $n) use ($user, &$url) {
            $url = $n->toMail($user)->actionUrl;

            return true;
        });
        $this->assertStringStartsWith($tunnel.'/email/verify/', (string) $url);

        // Le lien ouvert sur cette même origine (relayé par le proxy local) vérifie l'adresse.
        $this->actingAs($user, 'web')
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_HOST' => 'tunnel.exemple.test'])
            ->withHeaders(['X-Forwarded-Proto' => 'https', 'Accept' => 'text/html'])
            ->get((string) $url)->assertRedirect();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_verification_link_can_be_resent_through_the_queue(): void
    {
        Notification::fake();
        $this->register()->assertCreated();

        $this->fromSpa()->postJson('/api/v1/auth/email/verification-notification')->assertSuccessful();
        Notification::assertSentToTimes(User::sole(), VerifyEmailNotification::class, 2);
    }

    public function test_duplicate_email_weak_password_and_mismatched_confirmation_are_rejected(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'guillo@exemple.test']);

        $this->register(['email' => 'GUILLO@exemple.test'])->assertUnprocessable()
            ->assertJsonValidationErrors(['email'])
            ->assertJsonPath('errors.email.0', 'Impossible de créer un compte avec cette adresse e-mail.');
        $this->register(['email' => 'autre@exemple.test', 'password' => 'court', 'password_confirmation' => 'court'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);
        $this->register(['email' => 'autre@exemple.test', 'password_confirmation' => 'Different-Mot-de-passe-2026'])
            ->assertUnprocessable()->assertJsonValidationErrors(['password']);

        $this->assertSame(1, User::count());
    }

    public function test_once_verified_the_user_can_start_youtube_and_tiktok_connections(): void
    {
        Notification::fake();
        $this->configureYouTube();
        $this->configureTikTok();
        $this->register()->assertCreated();

        // Non vérifié : la connexion d'un réseau reste refusée (docs/security.md §3).
        $this->fromSpa()->postJson('/api/v1/social/youtube/connect')->assertForbidden();

        $user = User::sole();
        $user->markEmailAsVerified();
        $this->actingAs($user->fresh());
        $this->fromSpa()->postJson('/api/v1/social/youtube/connect')->assertOk()
            ->assertJsonPath('data.authorization_url', fn (string $u) => str_starts_with($u, 'https://accounts.google.com/'));
        $this->fromSpa()->postJson('/api/v1/social/tiktok/connect')->assertOk()
            ->assertJsonPath('data.authorization_url', fn (string $u) => str_starts_with($u, 'https://www.tiktok.com/v2/auth/authorize/'));
    }
}
