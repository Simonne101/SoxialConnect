<?php

namespace Tests\Feature\Media;

use App\Models\Media;
use App\Models\MediaFolder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Dossiers : création, nom unique, arborescence (5 niveaux), renommage, déplacement, suppression, IDOR. */
class MediaFolderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function create(array $data): TestResponse
    {
        return $this->actingAs($this->user)->fromSpa()->postJson('/api/v1/media/folders', $data);
    }

    public function test_create_list_with_counts(): void
    {
        $id = $this->create(['name' => '  Campagne   été  '])->assertCreated()
            ->assertJsonPath('data.name', 'Campagne été')->assertJsonPath('data.media_count', 0)->json('data.id');
        Media::factory()->count(2)->for($this->user)->create(['folder_id' => $id]);
        Media::factory()->for($this->user)->create(['folder_id' => $id, 'deleted_at' => now()]);
        MediaFolder::factory()->create(); // dossier d'un autre compte

        $this->actingAs($this->user)->fromSpa()->getJson('/api/v1/media/folders')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.media_count', 2);
    }

    public function test_names_are_unique_per_parent_and_validated(): void
    {
        $this->create(['name' => 'Produits'])->assertCreated();
        $this->create(['name' => 'Produits'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->create(['name' => ''])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->create(['name' => str_repeat('a', 121)])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->create(['name' => '../../etc'])->assertCreated()->assertJsonPath('data.name', '....etc');

        // Même nom autorisé dans un autre dossier parent.
        $parent = $this->create(['name' => 'Archives'])->json('data.id');
        $this->create(['name' => 'Produits', 'parent_id' => $parent])->assertCreated();
    }

    public function test_depth_is_limited_to_five_levels(): void
    {
        $parent = null;
        for ($level = 1; $level <= 5; $level++) {
            $parent = $this->create(['name' => "Niveau {$level}", 'parent_id' => $parent])->assertCreated()->json('data.id');
        }
        $this->create(['name' => 'Niveau 6', 'parent_id' => $parent])->assertStatus(409);
    }

    public function test_rename_and_move_without_cycles(): void
    {
        $a = $this->create(['name' => 'A'])->json('data.id');
        $b = $this->create(['name' => 'B', 'parent_id' => $a])->json('data.id');
        $c = $this->create(['name' => 'C'])->json('data.id');
        $api = fn () => $this->actingAs($this->user)->fromSpa();

        $api()->patchJson("/api/v1/media/folders/{$c}", ['name' => 'C renommé'])->assertOk()->assertJsonPath('data.name', 'C renommé');
        $api()->patchJson("/api/v1/media/folders/{$c}", ['parent_id' => $b])->assertOk()->assertJsonPath('data.parent_id', $b);
        $api()->patchJson("/api/v1/media/folders/{$a}", ['parent_id' => $c])->assertStatus(409); // A → dans son petit-enfant
        $api()->patchJson("/api/v1/media/folders/{$a}", ['parent_id' => $a])->assertStatus(409);
        $api()->patchJson("/api/v1/media/folders/{$c}", ['parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null);
    }

    public function test_delete_removes_subfolders_and_moves_media_to_root(): void
    {
        $a = $this->create(['name' => 'A'])->json('data.id');
        $b = $this->create(['name' => 'B', 'parent_id' => $a])->json('data.id');
        $inA = Media::factory()->for($this->user)->create(['folder_id' => $a]);
        $inB = Media::factory()->for($this->user)->create(['folder_id' => $b]);

        $this->actingAs($this->user)->fromSpa()->deleteJson("/api/v1/media/folders/{$a}")->assertNoContent();

        $this->assertDatabaseMissing('media_folders', ['id' => $a]);
        $this->assertDatabaseMissing('media_folders', ['id' => $b]);
        $this->assertNull($inA->fresh()->folder_id);
        $this->assertNull($inB->fresh()->folder_id);
        $this->assertNotSoftDeleted('media', ['id' => $inA->id]);
    }

    public function test_other_users_folders_are_unreachable(): void
    {
        $foreign = MediaFolder::factory()->create();
        $api = fn () => $this->actingAs($this->user)->fromSpa();

        $api()->patchJson("/api/v1/media/folders/{$foreign->id}", ['name' => 'pris'])->assertNotFound();
        $api()->patchJson("/api/v1/media/folders/{$foreign->id}", ['name' => ''])->assertNotFound();
        $api()->deleteJson("/api/v1/media/folders/{$foreign->id}")->assertNotFound();
        $this->create(['name' => 'Intrus', 'parent_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);

        $mine = $this->create(['name' => 'Moi'])->json('data.id');
        $api()->patchJson("/api/v1/media/folders/{$mine}", ['parent_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors(['parent_id']);
        $this->assertDatabaseHas('media_folders', ['id' => $foreign->id, 'name' => $foreign->name]);
    }
}
