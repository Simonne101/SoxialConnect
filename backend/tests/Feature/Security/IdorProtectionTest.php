<?php

namespace Tests\Feature\Security;

use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Protection IDOR (docs/security.md §4) : l'utilisateur A ne peut ni lire, ni modifier, ni supprimer,
 * ni référencer une ressource de l'utilisateur B en changeant un UUID. Réponse : 404 (existence non révélée).
 */
class IdorProtectionTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private Post $bobPost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
        $this->bobPost = Post::factory()->for($this->bob)->create(['title' => 'Secret de Bob']);
    }

    public function test_cannot_read_another_users_post(): void
    {
        $this->actingAs($this->alice)->fromSpa()->getJson("/api/v1/content/posts/{$this->bobPost->id}")
            ->assertNotFound()
            ->assertJsonMissing(['title' => 'Secret de Bob']);
    }

    public function test_cannot_update_another_users_post(): void
    {
        $this->actingAs($this->alice)->fromSpa()->patchJson("/api/v1/content/posts/{$this->bobPost->id}", [
            'mode' => 'draft', 'title' => 'Piraté', 'body' => 'x',
        ])->assertNotFound();

        $this->assertSame('Secret de Bob', $this->bobPost->fresh()->title);
    }

    public function test_cannot_delete_another_users_post(): void
    {
        $this->actingAs($this->alice)->fromSpa()->deleteJson("/api/v1/content/posts/{$this->bobPost->id}")->assertNotFound();
        $this->assertNotSoftDeleted('posts', ['id' => $this->bobPost->id]);
    }

    public function test_list_never_contains_other_users_posts(): void
    {
        Post::factory()->for($this->alice)->create();

        $ids = collect($this->actingAs($this->alice)->fromSpa()->getJson('/api/v1/content/posts')->assertOk()->json('data'))->pluck('id');

        $this->assertCount(1, $ids);
        $this->assertNotContains($this->bobPost->id, $ids);
    }

    public function test_cannot_target_another_users_social_account(): void
    {
        $bobAccount = SocialAccount::factory()->for($this->bob)->create();

        $this->actingAs($this->alice)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft', 'body' => 'x',
            'targets' => [['social_account_id' => $bobAccount->id, 'format' => 'feed']],
        ])->assertUnprocessable()->assertJsonValidationErrors(['targets.0.social_account_id']);

        $this->assertSame(0, Post::query()->where('user_id', $this->alice->id)->count());
    }

    public function test_cannot_attach_another_users_media(): void
    {
        $bobMedia = Media::factory()->for($this->bob)->create();

        $this->actingAs($this->alice)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'draft', 'body' => 'x', 'media_ids' => [$bobMedia->id],
        ])->assertUnprocessable()->assertJsonValidationErrors(['media_ids.0']);
    }

    public function test_social_accounts_list_is_scoped_to_owner(): void
    {
        SocialAccount::factory()->for($this->bob)->create();
        $mine = SocialAccount::factory()->for($this->alice)->create();

        $this->actingAs($this->alice)->fromSpa()->getJson('/api/v1/social/accounts')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
    }

    public function test_invalid_uuid_returns_404(): void
    {
        $this->actingAs($this->alice)->fromSpa()->getJson('/api/v1/content/posts/not-a-uuid')->assertNotFound();
    }
}
