<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Media\Concerns\MediaFixtures;
use Tests\TestCase;

/** Liste, filtres, tri, détail, renommage, déplacement, tags, suppression et IDOR. */
class MediaLibraryTest extends TestCase
{
    use MediaFixtures, RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('media');
        $this->user = User::factory()->create();
    }

    private function list(string $query = ''): TestResponse
    {
        return $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/media'.($query !== '' ? "?{$query}" : ''));
    }

    private function media(array $attributes = []): Media
    {
        return Media::factory()->for($this->user)->create($attributes);
    }

    // --- Liste -----------------------------------------------------------------------------------

    public function test_pagination_and_page_size_limits(): void
    {
        Media::factory()->count(5)->for($this->user)->create();

        $this->list('per_page=2')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.per_page', 2);
        $this->list()->assertOk()->assertJsonPath('meta.per_page', 20);
        $this->list('per_page=50')->assertOk()->assertJsonPath('meta.per_page', 50);
        $this->list('per_page=101')->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
        $ids = collect([1, 2, 3])->flatMap(fn ($p) => $this->list("per_page=2&page={$p}")->json('data.*.id'));
        $this->assertCount(5, $ids->unique());
    }

    public function test_filters_type_gif_folder_root_tag_search_and_ids(): void
    {
        $folder = MediaFolder::factory()->for($this->user)->create();
        $photo = $this->media(['original_name' => 'logo-bleu.png', 'mime_type' => 'image/png', 'tags' => ['marque', 'logo'], 'folder_id' => $folder->id]);
        $gif = $this->media(['original_name' => 'fete.gif', 'mime_type' => 'image/gif', 'tags' => ['fête']]);
        $video = $this->media(['original_name' => 'clip 100%.mp4', 'type' => 'video', 'mime_type' => 'video/mp4']);

        $this->list('type=video')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $video->id);
        $this->list('type=gif')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_gif', true);
        $this->list('type=image')->assertOk()->assertJsonCount(2, 'data');
        $this->list("folder={$folder->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $photo->id);
        $this->list('folder=root')->assertOk()->assertJsonCount(2, 'data');
        $this->list('tag=MARQUE')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $photo->id);
        $this->list('tag='.urlencode('fête'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $gif->id);
        $this->list('search=logo')->assertOk()->assertJsonCount(1, 'data');
        $this->list('search='.urlencode('%'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $video->id);
        $this->list("ids[]={$gif->id}&ids[]={$video->id}")->assertOk()->assertJsonCount(2, 'data');

        $this->list('type=exe')->assertUnprocessable()->assertJsonValidationErrors(['type']);
        $this->list('folder=../../etc')->assertUnprocessable()->assertJsonValidationErrors(['folder']);
    }

    public function test_sort_accepts_only_whitelisted_keys(): void
    {
        $small = $this->media(['original_name' => 'b.jpg', 'size_bytes' => 10]);
        $big = $this->media(['original_name' => 'a.jpg', 'size_bytes' => 9000]);

        $this->list('sort=name&direction=asc')->assertOk()->assertJsonPath('data.0.id', $big->id);
        $this->list('sort=size&direction=asc')->assertOk()->assertJsonPath('data.0.id', $small->id);
        $this->list('sort=size&direction=desc')->assertOk()->assertJsonPath('data.0.id', $big->id);

        foreach (['path', 'user_id', 'id;DROP TABLE media', 'original_name'] as $sort) {
            $this->list('sort='.urlencode($sort))->assertUnprocessable()->assertJsonValidationErrors(['sort']);
        }
        $this->list('direction=sideways')->assertUnprocessable()->assertJsonValidationErrors(['direction']);
    }

    public function test_list_never_contains_other_users_media_and_exposes_antivirus_state(): void
    {
        $mine = $this->media();
        Media::factory()->count(3)->create();

        $this->list()->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id)
            ->assertJsonPath('meta.antivirus.available', false)
            ->assertJsonPath('meta.antivirus.required', false);
    }

    // --- Détail, renommage, déplacement -------------------------------------------------------

    public function test_show_rename_and_move(): void
    {
        $media = $this->media(['original_name' => 'photo.jpg', 'extension' => 'jpg']);
        $folder = MediaFolder::factory()->for($this->user)->create();

        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/media/{$media->id}")->assertOk()->assertJsonPath('data.id', $media->id);

        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/media/{$media->id}", ['name' => '../Nouveau nom.exe'])
            ->assertOk()->assertJsonPath('data.name', 'Nouveau nom.jpg');

        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/media/{$media->id}", ['folder_id' => $folder->id])
            ->assertOk()->assertJsonPath('data.folder_id', $folder->id);
        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/media/{$media->id}", ['folder_id' => null])
            ->assertOk()->assertJsonPath('data.folder_id', null);

        $foreign = MediaFolder::factory()->create();
        $this->actingAs($this->user)->fromSpa()->patchJson("/api/v1/media/{$media->id}", ['folder_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors(['folder_id']);
    }

    // --- Tags ----------------------------------------------------------------------------------

    public function test_add_remove_and_list_tags(): void
    {
        $media = $this->media(['tags' => null]);
        $api = fn () => $this->actingAs($this->user)->fromSpa();

        $api()->postJson("/api/v1/media/{$media->id}/tags", ['tag' => ' #Été 2026 '])->assertOk()->assertJsonPath('data.tags', ['été 2026']);
        $api()->postJson("/api/v1/media/{$media->id}/tags", ['tag' => 'été 2026'])->assertOk()->assertJsonCount(1, 'data.tags');
        // Ordre naturel en français, sans tenir compte des accents.
        $api()->postJson("/api/v1/media/{$media->id}/tags", ['tag' => 'marque'])->assertOk()->assertJsonPath('data.tags', ['été 2026', 'marque']);
        $api()->postJson("/api/v1/media/{$media->id}/tags", ['tag' => '<b>'])->assertUnprocessable()->assertJsonValidationErrors(['tag']);
        $this->media(['tags' => ['autre']]);
        Media::factory()->create(['tags' => ['secret-voisin']]);

        $api()->getJson('/api/v1/media/tags')->assertOk()->assertExactJson([
            'data' => ['autre', 'été 2026', 'marque'], 'message' => null, 'meta' => [],
        ]);

        $api()->deleteJson("/api/v1/media/{$media->id}/tags/".rawurlencode('Été 2026'))->assertOk()->assertJsonPath('data.tags', ['marque']);
    }

    public function test_tag_limit_per_media(): void
    {
        config(['media.tags.max_per_media' => 2]);
        $media = $this->media(['tags' => ['a', 'b']]);

        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/media/{$media->id}/tags", ['tag' => 'c'])->assertStatus(409);
    }

    // --- Suppression ---------------------------------------------------------------------------

    public function test_delete_is_logical_and_keeps_the_file_until_purge(): void
    {
        $id = $this->storeMedia($this->user, $this->png())->assertCreated()->json('data.id');
        $path = Media::findOrFail($id)->path;

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/media/{$id}")->assertNoContent();

        $this->assertSoftDeleted('media', ['id' => $id, 'status' => 'deleted']);
        Storage::disk('media')->assertExists($path);
        $this->list()->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->user)->fromSpa()->getJson("/api/v1/media/{$id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'MEDIA_DELETED', 'subject_id' => $id]);
    }

    public function test_media_used_by_a_scheduled_post_cannot_be_deleted(): void
    {
        $media = $this->media();
        $account = SocialAccount::factory()->for($this->user)->create();
        $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/content/posts', [
            'mode' => 'schedule',
            'body' => 'Avec image',
            'scheduled_at' => now()->addDay()->toIso8601String(),
            'media_ids' => [$media->id],
            'targets' => [['social_account_id' => $account->id, 'format' => 'feed']],
        ])->assertCreated();

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/media/{$media->id}")->assertStatus(409);
        $this->assertNotSoftDeleted('media', ['id' => $media->id]);

        // Une fois la programmation annulée, la suppression est possible.
        $post = Post::query()->ownedBy($this->user)->firstOrFail();
        $this->actingAs($this->user)->fromSpa()->postJson("/api/v1/content/posts/{$post->id}/cancel")->assertOk();
        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/media/{$media->id}")->assertNoContent();
    }

    // --- Quarantaine ---------------------------------------------------------------------------

    public function test_unscanned_media_are_quarantined_when_a_scan_is_required(): void
    {
        $media = $this->media(['scan_status' => 'pending']);
        $this->list()->assertOk()->assertJsonPath('data.0.usable', true);

        config(['media.scan.required' => true]);
        $this->list()->assertOk()->assertJsonPath('data.0.usable', false)->assertJsonPath('data.0.url', null);
        $this->assertFalse($media->fresh()->isUsable());
    }

    // --- IDOR ------------------------------------------------------------------------------------

    public function test_other_users_media_are_unreachable_even_with_invalid_payloads(): void
    {
        $foreign = Media::factory()->create(['tags' => ['x']]);
        $api = fn () => $this->actingAs($this->user)->fromSpa();

        $api()->getJson("/api/v1/media/{$foreign->id}")->assertNotFound();
        $api()->patchJson("/api/v1/media/{$foreign->id}", ['name' => 'pris'])->assertNotFound();
        $api()->patchJson("/api/v1/media/{$foreign->id}", ['folder_id' => 'pas-un-uuid'])->assertNotFound();
        $api()->postJson("/api/v1/media/{$foreign->id}/tags", ['tag' => '<invalide>'])->assertNotFound();
        $api()->deleteJson("/api/v1/media/{$foreign->id}/tags/x")->assertNotFound();
        $api()->deleteJson("/api/v1/media/{$foreign->id}")->assertNotFound();
        $api()->getJson('/api/v1/media/not-a-uuid')->assertNotFound();

        $this->assertNotSoftDeleted('media', ['id' => $foreign->id]);
        $this->assertSame(['x'], $foreign->fresh()->tags);
    }

    public function test_guests_are_rejected(): void
    {
        $media = $this->media();
        $this->fromSpa()->getJson('/api/v1/media')->assertUnauthorized();
        $this->fromSpa()->getJson("/api/v1/media/{$media->id}")->assertUnauthorized();
        $this->fromSpa()->getJson('/api/v1/media/tags')->assertUnauthorized();
    }
}
