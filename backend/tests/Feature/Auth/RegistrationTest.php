<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Marie Dubois',
            'email' => 'Marie.Dubois@Example.com',
            'password' => 'une-phrase-de-passe-longue',
            'password_confirmation' => 'une-phrase-de-passe-longue',
        ], $overrides);
    }

    public function test_register_creates_user_with_argon2id_and_returns_201(): void
    {
        Notification::fake();

        $response = $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload());

        $response->assertCreated()
            ->assertJsonPath('data.email', 'marie.dubois@example.com')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonStructure(['data' => ['id', 'name', 'email'], 'message', 'meta'])
            ->assertJsonMissingPath('data.password');

        $user = User::firstWhere('email', 'marie.dubois@example.com');
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('une-phrase-de-passe-longue', $user->password));
        $this->assertStringStartsWith('$argon2id$', $user->password);
        $this->assertAuthenticatedAs($user);
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'action' => 'REGISTERED']);
    }

    public function test_register_validates_input_with_422(): void
    {
        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload([
            'email' => 'pas-un-email',
            'password' => 'court',
            'password_confirmation' => 'autre',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->assertSame(0, User::count());
    }

    public function test_register_rejects_duplicate_email_case_insensitively(): void
    {
        User::factory()->create(['email' => 'marie.dubois@example.com']);

        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload(['email' => 'MARIE.DUBOIS@example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_register_ignores_privileged_fields(): void
    {
        Notification::fake();

        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload([
            'status' => 'active',
            'email_verified_at' => now()->toIso8601String(),
        ]))->assertCreated();

        $this->assertNull(User::firstWhere('email', 'marie.dubois@example.com')->email_verified_at);
    }

    public function test_register_is_rate_limited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload(['email' => "x{$i}", 'password' => 'a']));
        }

        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload())
            ->assertStatus(429)
            ->assertJsonStructure(['message', 'errors'])
            ->assertHeader('Retry-After');
    }

    public function test_audit_log_never_contains_password(): void
    {
        Notification::fake();
        $this->fromSpa()->postJson('/api/v1/auth/register', $this->payload())->assertCreated();

        foreach (AuditLog::all() as $log) {
            $this->assertStringNotContainsString('une-phrase-de-passe-longue', json_encode($log->toArray()));
        }
    }
}
