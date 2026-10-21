<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Résistance aux attaques sur la connexion (étape 8, lot 5) : « credential stuffing » (une adresse différente à
 * chaque essai depuis une même IP), verrouillage progressif d'un compte, réponses identiques quel que soit le cas.
 */
class LoginProtectionTest extends TestCase
{
    use RefreshDatabase;

    private function attempt(string $email, string $password = 'mauvais-mot-de-passe', string $ip = '198.51.100.10')
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->fromSpa()
            ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
    }

    public function test_credential_stuffing_from_one_ip_is_blocked_across_different_emails(): void
    {
        config(['security.rate_limits.login_ip_per_minute' => 20]);
        User::factory()->create(['email' => 'victime@example.com', 'password' => 'une-phrase-de-passe-longue']);

        for ($i = 0; $i < 20; $i++) {
            $this->attempt("compte{$i}@example.com")->assertUnprocessable(); // une seule tentative par adresse
        }

        $this->attempt('victime@example.com', 'une-phrase-de-passe-longue')->assertStatus(429);
        $this->assertGuest('web');
        $this->attempt('victime@example.com', 'une-phrase-de-passe-longue', '203.0.113.50')->assertOk(); // autre IP : non bloquée
    }

    public function test_an_account_is_locked_progressively_beyond_the_per_minute_window(): void
    {
        config(['security.rate_limits.login_per_minute' => 5, 'security.rate_limits.login_per_hour' => 20]);
        User::factory()->create(['email' => 'marie@example.com', 'password' => 'une-phrase-de-passe-longue']);

        for ($minute = 0; $minute < 4; $minute++) {
            for ($i = 0; $i < 5; $i++) {
                $this->attempt('marie@example.com')->assertUnprocessable();
            }
            $this->travel(61)->seconds();
        }

        // 20 échecs en 4 minutes : la fenêtre de la minute est passée, mais le plafond horaire bloque encore.
        $this->attempt('marie@example.com', 'une-phrase-de-passe-longue')->assertStatus(429);
        $this->travel(61)->minutes();
        $this->attempt('marie@example.com', 'une-phrase-de-passe-longue')->assertOk();
    }

    public function test_known_and_unknown_emails_get_exactly_the_same_answer(): void
    {
        User::factory()->create(['email' => 'marie@example.com', 'password' => 'une-phrase-de-passe-longue']);
        User::factory()->create(['email' => 'suspendu@example.com', 'password' => 'une-phrase-de-passe-longue', 'status' => 'suspended']);

        $answers = collect([
            $this->attempt('marie@example.com', 'mauvais', '198.51.100.1'),
            $this->attempt('inconnu@example.com', 'mauvais', '198.51.100.2'),
            $this->attempt('suspendu@example.com', 'une-phrase-de-passe-longue', '198.51.100.3'),
        ])->map(fn ($r) => [$r->status(), $r->json()]);

        $this->assertCount(1, $answers->unique(fn ($a) => json_encode($a)), 'Même statut et même corps : aucune énumération.');
    }

    public function test_password_reset_answers_identically_for_known_and_unknown_emails(): void
    {
        User::factory()->create(['email' => 'marie@example.com']);

        $known = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'marie@example.com']);
        $unknown = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.21'])->fromSpa()->postJson('/api/v1/auth/forgot-password', ['email' => 'inconnu@example.com']);

        $this->assertSame([$known->status(), $known->json()], [$unknown->status(), $unknown->json()]);
    }
}
