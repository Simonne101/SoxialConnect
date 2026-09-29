<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Codes HTTP et format d'erreur uniforme { message, errors } de l'API. */
class ApiErrorHandlingTest extends TestCase
{
    use RefreshDatabase;

    public function test_200_health(): void
    {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('data.status', 'ok');
    }

    public function test_400_malformed_json(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->fromSpa()
            ->call('POST', '/api/v1/content/posts', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"mode": "draft",')
            ->assertStatus(400)
            ->assertJsonStructure(['message', 'errors']);
    }

    public function test_401_without_session(): void
    {
        $this->fromSpa()->getJson('/api/v1/content/posts')->assertUnauthorized()->assertJsonStructure(['message', 'errors']);
    }

    public function test_403_unverified_email_on_content(): void
    {
        $this->actingAs(User::factory()->unverified()->create())->fromSpa()->getJson('/api/v1/content/posts')
            ->assertForbidden()
            ->assertJsonPath('message', 'Vérifiez votre adresse e-mail pour accéder à cette fonctionnalité.');
    }

    public function test_403_suspended_user_cannot_create(): void
    {
        $this->actingAs(User::factory()->suspended()->create())->fromSpa()
            ->postJson('/api/v1/content/posts', ['mode' => 'draft', 'body' => 'x'])
            ->assertForbidden();
    }

    public function test_404_unknown_route(): void
    {
        $this->getJson('/api/v1/nexiste-pas')->assertNotFound()->assertExactJson(['message' => 'Ressource introuvable.', 'errors' => []]);
    }

    public function test_422_validation_format(): void
    {
        $this->actingAs(User::factory()->create())->fromSpa()->postJson('/api/v1/content/posts', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['message', 'errors' => ['mode']]);
    }

    public function test_429_general_api_limit(): void
    {
        config(['security.rate_limits.api_per_minute' => 2]);

        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertOk();
        $this->getJson('/api/v1/health')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_errors_never_expose_stack_traces_in_production_mode(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/v1/nexiste-pas')->assertJsonMissingPath('trace')->assertJsonMissingPath('exception');
    }
}
