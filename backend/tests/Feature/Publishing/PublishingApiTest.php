<?php

namespace Tests\Feature\Publishing;

use App\Domain\Users\Enums\UserStatus;
use App\Jobs\PublishPostVariantJob;
use App\Models\Post;
use App\Models\PublicationAttempt;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** /api/v1/content/posts/{post}/publish|retry|attempts : contrat, validation, IDOR. */
class PublishingApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
    }

    private function createPost(array $overrides = []): array
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', array_merge([
            'mode' => 'draft',
            'body' => 'Texte de la publication.',
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed']],
        ], $overrides))->assertCreated()->json('data');
    }

    private function endpoint(string $method, string $id, string $action, array $body = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->user)->fromSpa()->json($method, "/api/v1/content/posts/{$id}/{$action}", $body);
    }

    public function test_publish_returns_the_queued_post(): void
    {
        Queue::fake();
        $post = $this->createPost();

        $this->endpoint('POST', $post['id'], 'publish', ['lock_version' => $post['lock_version']])
            ->assertOk()
            ->assertJsonPath('message', "Publication en file d'attente.")
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.variants.0.status', 'queued')
            ->assertJsonPath('data.variants.0.attempts_count', 0)
            ->assertJsonPath('data.lock_version', $post['lock_version'] + 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_PUBLISH_REQUESTED', 'subject_id' => $post['id']]);
    }

    public function test_publish_then_attempts_show_the_failure_without_internal_fields(): void
    {
        $post = $this->createPost(); // file sync : exécution immédiate

        $this->endpoint('POST', $post['id'], 'publish')->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.variants.0.status', 'failed')
            ->assertJsonPath('data.variants.0.error_code', 'NOT_CONFIGURED')
            ->assertJsonPath('data.variants.0.attempts_count', 1);

        $response = $this->endpoint('GET', $post['id'], 'attempts')->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.attempt_number', 1)
            ->assertJsonPath('data.0.status', 'failed')
            ->assertJsonPath('data.0.trigger', 'manual')
            ->assertJsonPath('data.0.error_code', 'NOT_CONFIGURED')
            ->assertJsonPath('data.0.retryable', false);
        $this->assertArrayNotHasKey('idempotency_key', $response->json('data.0'));
        $this->assertArrayNotHasKey('job_uuid', $response->json('data.0'));
        $this->assertArrayNotHasKey('response_excerpt', $response->json('data.0'));

        $this->endpoint('POST', $post['id'], 'retry')->assertOk()->assertJsonPath('message', "Nouvelle tentative en file d'attente.");
        $this->endpoint('GET', $post['id'], 'attempts')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.attempt_number', 2)->assertJsonPath('data.0.trigger', 'retry');
        Http::assertNothingSent();
    }

    public function test_publish_requires_targets_and_content(): void
    {
        $post = $this->createPost(['targets' => []]);
        $this->endpoint('POST', $post['id'], 'publish')->assertStatus(422)->assertJsonValidationErrors('targets');

        $expired = SocialAccount::factory()->for($this->user)->expired()->create();
        $post = Post::factory()->for($this->user)->create();
        $post->variants()->create(['social_account_id' => $expired->id, 'format' => 'feed']);
        $this->endpoint('POST', $post->id, 'publish')->assertStatus(422)->assertJsonValidationErrors('targets');
        $this->assertSame(0, PublicationAttempt::count());
    }

    public function test_publish_rejects_a_stale_version_and_non_publishable_posts(): void
    {
        Queue::fake();
        $post = $this->createPost();
        $this->endpoint('POST', $post['id'], 'publish', ['lock_version' => $post['lock_version'] + 5])->assertStatus(409);

        $this->endpoint('POST', $post['id'], 'publish')->assertOk();
        // Déjà en cours : ni double mise en file, ni modification.
        $this->endpoint('POST', $post['id'], 'publish')->assertStatus(409);
        Queue::assertPushed(PublishPostVariantJob::class, 1);
        $this->actingAs($this->user)->fromSpa()->putJson("/api/v1/content/posts/{$post['id']}", ['mode' => 'draft', 'body' => 'x', 'targets' => []])->assertStatus(409);
    }

    public function test_retry_is_refused_unless_the_post_failed(): void
    {
        $post = $this->createPost();
        $this->endpoint('POST', $post['id'], 'retry')->assertStatus(409)->assertJsonPath('message', 'Seule une publication en échec peut être relancée.');
    }

    public function test_another_users_post_is_invisible_for_every_publishing_endpoint(): void
    {
        $post = $this->createPost();
        $this->endpoint('POST', $post['id'], 'publish'); // → failed (sync)
        $intruder = User::factory()->create();

        $this->endpoint('POST', $post['id'], 'publish', [], $intruder)->assertNotFound();
        $this->endpoint('POST', $post['id'], 'retry', [], $intruder)->assertNotFound();
        $this->endpoint('POST', $post['id'], 'cancel', [], $intruder)->assertNotFound();
        $this->endpoint('GET', $post['id'], 'attempts', [], $intruder)->assertNotFound();
        // Même avec un corps invalide : 404 avant la validation (pas de fuite par un 422).
        $this->endpoint('POST', $post['id'], 'retry', ['confirm_unknown' => 'pas-un-booléen'], $intruder)->assertNotFound();

        $this->assertSame(1, PublicationAttempt::count());
    }

    public function test_guests_and_suspended_users_cannot_publish(): void
    {
        $post = $this->createPost();
        $this->app['auth']->forgetGuards();
        $this->fromSpa()->postJson("/api/v1/content/posts/{$post['id']}/publish")->assertUnauthorized();

        $this->user->forceFill(['status' => UserStatus::Suspended])->save();
        $this->endpoint('POST', $post['id'], 'publish')->assertForbidden();
        $this->assertSame(0, PublicationAttempt::count());
    }
}
