<?php

namespace Tests\Feature\Content;

use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private SocialAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->account = SocialAccount::factory()->for($this->user)->create();
    }

    private function draft(array $overrides = []): array
    {
        return array_merge([
            'mode' => 'draft',
            'title' => 'Conseils visibilité',
            'body' => 'Voici 5 leviers simples pour booster votre visibilité.',
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed', 'options' => ['hashtags' => ['conseils']]]],
        ], $overrides);
    }

    public function test_create_draft_returns_201_with_variants(): void
    {
        $response = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft());

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.variants.0.social_account_id', $this->account->id)
            ->assertJsonPath('data.variants.0.status', 'draft')
            ->assertJsonPath('message', 'Brouillon enregistré.');

        $this->assertDatabaseHas('posts', ['id' => $response->json('data.id'), 'user_id' => $this->user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'POST_CREATED', 'subject_id' => $response->json('data.id')]);
    }

    public function test_schedule_requires_future_date_and_connected_account(): void
    {
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft(['mode' => 'schedule', 'scheduled_at' => now()->subHour()->toIso8601String()]))
            ->assertUnprocessable()->assertJsonValidationErrors(['scheduled_at']);

        $expired = SocialAccount::factory()->for($this->user)->expired()->create();
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft([
            'mode' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'targets' => [['social_account_id' => $expired->id, 'format' => 'feed']],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);

        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft([
            'mode' => 'schedule',
            'scheduled_at' => now()->addDay()->toIso8601String(),
        ]))->assertCreated()->assertJsonPath('data.status', 'scheduled')->assertJsonPath('data.variants.0.status', 'scheduled');
    }

    public function test_media_must_be_owned_and_ready(): void
    {
        $ready = Media::factory()->for($this->user)->create();
        $processing = Media::factory()->for($this->user)->processing()->create();

        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft(['media_ids' => [$processing->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors(['media_ids.0']);

        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft(['media_ids' => [$ready->id]]))
            ->assertCreated()->assertJsonPath('data.media_ids.0', $ready->id);
    }

    public function test_unknown_option_keys_are_rejected(): void
    {
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft([
            'targets' => [['social_account_id' => $this->account->id, 'format' => 'feed', 'options' => ['script' => '<script>']]],
        ]))->assertUnprocessable()->assertJsonValidationErrors(['targets.0.options']);
    }

    public function test_index_is_paginated_and_filtered(): void
    {
        Post::factory()->count(3)->for($this->user)->create();
        Post::factory()->for($this->user)->scheduled()->create();

        $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/content/posts?per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 4)->assertJsonPath('meta.last_page', 2);

        $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/content/posts?status[]=scheduled')
            ->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_show_update_and_delete(): void
    {
        $id = $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', $this->draft())->json('data.id');

        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$id}")
            ->assertOk()->assertJsonPath('data.id', $id);

        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/content/posts/{$id}", $this->draft(['title' => 'Nouveau titre', 'lock_version' => 0]))
            ->assertOk()->assertJsonPath('data.title', 'Nouveau titre')->assertJsonPath('data.lock_version', 1);

        // Verrou optimiste : une ancienne version est refusée (409).
        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/content/posts/{$id}", $this->draft(['lock_version' => 0]))
            ->assertStatus(409)->assertJsonStructure(['message', 'errors']);

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/content/posts/{$id}")->assertNoContent();
        $this->assertSoftDeleted('posts', ['id' => $id]);
        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/content/posts/{$id}")->assertNotFound();
    }

    public function test_published_post_cannot_be_modified(): void
    {
        $post = Post::factory()->for($this->user)->published()->create();

        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/content/posts/{$post->id}", $this->draft())
            ->assertStatus(409);
    }
}
