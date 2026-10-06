<?php

namespace Tests\Feature\Video;

use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** API Video Studio (ADR-044) : projets, scènes, pistes et médias du projet. */
class VideoProjectApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    /** @return array<string, mixed> projet créé (réponse API) */
    private function createProject(array $data = []): array
    {
        return $this->api()->postJson('/api/v1/video/projects', $data + ['title' => 'Lancement produit'])->assertCreated()->json('data');
    }

    // --- Projets --------------------------------------------------------------------------------

    public function test_creating_a_project_initializes_format_tracks_and_a_scene_in_one_go(): void
    {
        $response = $this->api()->postJson('/api/v1/video/projects', [
            'title' => 'Lancement produit', 'description' => 'Teaser 30 s', 'aspect_ratio' => '16:9', 'fps' => 25,
            'user_id' => User::factory()->create()->id, // ignoré : propriétaire = utilisateur connecté
        ])->assertCreated()
            ->assertJsonPath('data.kind', 'composition')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.aspect_ratio', '16:9')
            ->assertJsonPath('data.width', 1920)
            ->assertJsonPath('data.height', 1080)
            ->assertJsonPath('data.fps', 25)
            ->assertJsonPath('data.duration_ms', 0)
            ->assertJsonPath('data.lock_version', 0)
            ->assertJsonPath('data.tracks.*.type', ['video', 'text', 'audio'])
            ->assertJsonPath('data.tracks.*.position', [0, 1, 2])
            ->assertJsonCount(1, 'data.scenes')
            ->assertJsonPath('data.scenes.0.duration_ms', 5000);
        $this->assertIsBool($response->json('meta.renderer_available'));

        $this->assertSame($this->user->id, VideoProject::sole()->user_id);
        $this->assertArrayNotHasKey('user_id', $response->json('data'));
    }

    public function test_defaults_and_validation_of_a_project(): void
    {
        $this->createProject();
        $project = VideoProject::sole();
        $this->assertSame('9:16', $project->aspect_ratio->value);
        $this->assertSame([1080, 1920, 30], [$project->width, $project->height, $project->fps]);

        $this->api()->postJson('/api/v1/video/projects', ['title' => '', 'aspect_ratio' => '4:3', 'fps' => 60, 'target_duration_ms' => 999_999])
            ->assertUnprocessable()->assertJsonValidationErrors(['title', 'aspect_ratio', 'fps', 'target_duration_ms']);
    }

    public function test_listing_reading_updating_and_deleting_a_project(): void
    {
        $id = $this->createProject()['id'];
        VideoProject::factory()->for(User::factory())->create(); // projet d'un autre compte
        VideoProject::factory()->for($this->user)->clipping()->create(); // clipping : hors Video Studio

        $this->api()->getJson('/api/v1/video/projects')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.total', 1);
        $this->api()->getJson("/api/v1/video/projects/{$id}")->assertOk()->assertJsonPath('data.title', 'Lancement produit')->assertJsonCount(3, 'data.tracks');

        $this->api()->patchJson("/api/v1/video/projects/{$id}", ['title' => 'Nouveau titre', 'aspect_ratio' => '1:1', 'lock_version' => 0])->assertOk()
            ->assertJsonPath('data.title', 'Nouveau titre')->assertJsonPath('data.width', 1080)->assertJsonPath('data.height', 1080)->assertJsonPath('data.lock_version', 1);
        $this->api()->patchJson("/api/v1/video/projects/{$id}", ['title' => 'Écrasement', 'lock_version' => 0])
            ->assertStatus(409)->assertJsonPath('code', 'STALE_VERSION');

        $this->api()->deleteJson("/api/v1/video/projects/{$id}")->assertNoContent();
        $this->assertSoftDeleted('video_projects', ['id' => $id]);
        $this->api()->getJson("/api/v1/video/projects/{$id}")->assertNotFound();
    }

    public function test_clipping_projects_are_not_editable_through_the_studio(): void
    {
        $clipping = VideoProject::factory()->for($this->user)->clipping()->create();

        $this->api()->postJson("/api/v1/video/projects/{$clipping->id}/scenes", ['duration_ms' => 1000])->assertStatus(409)->assertJsonPath('code', 'NOT_COMPOSITION');
    }

    // --- Scènes -----------------------------------------------------------------------------------

    public function test_scenes_are_created_ordered_reordered_and_deleted(): void
    {
        $project = $this->createProject();
        $first = $project['scenes'][0]['id'];
        $second = $this->api()->postJson("/api/v1/video/projects/{$project['id']}/scenes", ['name' => 'Accroche', 'role' => 'hook', 'duration_ms' => 3000])
            ->assertCreated()->assertJsonPath('data.position', 1)->assertJsonPath('meta.lock_version', 1)->json('data.id');
        $third = $this->api()->postJson("/api/v1/video/projects/{$project['id']}/scenes", ['duration_ms' => 2000, 'position' => 0, 'on_screen_text' => 'Bonjour'])
            ->assertCreated()->assertJsonPath('data.position', 0)->json('data.id');

        $this->assertSame([$third, $first, $second], $this->sceneOrder($project['id']));

        // Déplacement en dernière position, modification des champs.
        $this->api()->patchJson("/api/v1/video/scenes/{$third}", ['position' => 2, 'duration_ms' => 4000, 'role' => 'cta'])->assertOk()
            ->assertJsonPath('data.position', 2)->assertJsonPath('data.role', 'cta');
        $this->assertSame([$first, $second, $third], $this->sceneOrder($project['id']));

        // Suppression : positions renumérotées sans trou.
        $this->api()->deleteJson("/api/v1/video/scenes/{$first}")->assertOk();
        $this->assertSame([$second, $third], $this->sceneOrder($project['id']));
        $this->assertSame([0, 1], DB::table('video_scenes')->orderBy('position')->pluck('position')->map(fn ($p) => (int) $p)->all());
    }

    public function test_scene_validation_and_background_media(): void
    {
        $project = $this->createProject();
        $scene = $project['scenes'][0]['id'];

        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/scenes", ['duration_ms' => 400, 'role' => 'outro'])
            ->assertUnprocessable()->assertJsonValidationErrors(['duration_ms', 'role']);

        $image = Media::factory()->for($this->user)->create();
        $this->api()->patchJson("/api/v1/video/scenes/{$scene}", ['media_id' => $image->id])->assertOk()
            ->assertJsonPath('data.media_type', 'image')->assertJsonPath('data.media.id', $image->id);
        $this->api()->patchJson("/api/v1/video/scenes/{$scene}", ['media_start_ms' => 500])
            ->assertUnprocessable()->assertJsonValidationErrors('media_start_ms');

        $foreign = Media::factory()->for(User::factory())->create();
        $this->api()->patchJson("/api/v1/video/scenes/{$scene}", ['media_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('media_id');
        $audio = Media::factory()->for($this->user)->create(['type' => 'audio', 'mime_type' => 'audio/mpeg', 'extension' => 'mp3']);
        $this->api()->patchJson("/api/v1/video/scenes/{$scene}", ['media_id' => $audio->id])
            ->assertUnprocessable()->assertJsonValidationErrors('media_id');
    }

    public function test_scene_limit_is_enforced(): void
    {
        config(['video.limits.max_scenes' => 2]);
        $project = $this->createProject();
        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/scenes", ['duration_ms' => 1000])->assertCreated();
        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/scenes", ['duration_ms' => 1000])->assertStatus(409)->assertJsonPath('code', 'LIMIT_REACHED');
    }

    // --- Pistes -----------------------------------------------------------------------------------

    public function test_tracks_are_created_reordered_locked_and_deleted(): void
    {
        $project = $this->createProject();
        $overlay = $this->api()->postJson("/api/v1/video/projects/{$project['id']}/tracks", ['type' => 'overlay', 'name' => 'Logo', 'position' => 0])
            ->assertCreated()->assertJsonPath('data.position', 0)->assertJsonPath('data.name', 'Logo')->json('data.id');

        $types = fn () => collect($this->api()->getJson("/api/v1/video/projects/{$project['id']}/tracks")->assertOk()->json('data'))->pluck('type')->all();
        $this->assertSame(['overlay', 'video', 'text', 'audio'], $types());

        $this->api()->patchJson("/api/v1/video/tracks/{$overlay}", ['position' => 3, 'muted' => true, 'locked' => true])->assertOk()
            ->assertJsonPath('data.position', 3)->assertJsonPath('data.locked', true);
        $this->assertSame(['video', 'text', 'audio', 'overlay'], $types());

        // Verrouillée : rien ne change, sauf le déverrouillage.
        $this->api()->patchJson("/api/v1/video/tracks/{$overlay}", ['name' => 'X'])->assertStatus(409)->assertJsonPath('code', 'TRACK_LOCKED');
        $this->api()->deleteJson("/api/v1/video/tracks/{$overlay}")->assertStatus(409);
        $this->api()->patchJson("/api/v1/video/tracks/{$overlay}", ['locked' => false])->assertOk();
        $this->api()->deleteJson("/api/v1/video/tracks/{$overlay}")->assertOk();
        $this->assertSame(['video', 'text', 'audio'], $types());

        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/tracks", ['type' => 'music'])->assertUnprocessable()->assertJsonValidationErrors('type');
    }

    public function test_track_limit_is_enforced(): void
    {
        config(['video.limits.max_tracks' => 3]);
        $project = $this->createProject(); // 3 pistes initiales
        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/tracks", ['type' => 'text'])->assertStatus(409)->assertJsonPath('code', 'LIMIT_REACHED');
    }

    // --- Médias du projet (médiathèque existante) ------------------------------------------------

    public function test_assets_reference_existing_library_media_without_copying_them(): void
    {
        $project = $this->createProject();
        $video = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'duration_ms' => 12_000]);
        $before = Media::count();

        $asset = $this->api()->postJson("/api/v1/video/projects/{$project['id']}/assets", ['media_id' => $video->id])->assertCreated()
            ->assertJsonPath('data.media_id', $video->id)->assertJsonPath('data.available', true)
            ->assertJsonPath('data.media.type', 'video')->json('data');
        $this->assertStringContainsString('signature=', $asset['media']['url'], 'URL signée, jamais un chemin de fichier.');
        $this->assertStringNotContainsString('storage/app', json_encode($asset));
        $this->assertSame($before, Media::count(), 'Aucune copie du média.');

        // Idempotent : le même média n'est rattaché qu'une fois.
        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/assets", ['media_id' => $video->id])->assertCreated()->assertJsonPath('data.id', $asset['id']);
        $this->api()->getJson("/api/v1/video/projects/{$project['id']}/assets")->assertOk()->assertJsonCount(1, 'data');

        $this->api()->deleteJson("/api/v1/video/assets/{$asset['id']}")->assertOk();
        $this->assertNotNull(Media::find($video->id), 'Le média reste dans la médiathèque.');
    }

    public function test_only_ready_usable_media_of_the_owner_can_be_attached(): void
    {
        $project = $this->createProject();
        foreach ([
            Media::factory()->for(User::factory())->create(),
            Media::factory()->for($this->user)->processing()->create(),
            Media::factory()->for($this->user)->create(['type' => 'document', 'mime_type' => 'application/pdf', 'extension' => 'pdf']),
        ] as $media) {
            $this->api()->postJson("/api/v1/video/projects/{$project['id']}/assets", ['media_id' => $media->id])
                ->assertUnprocessable()->assertJsonValidationErrors('media_id');
        }
        $this->api()->postJson("/api/v1/video/projects/{$project['id']}/assets", ['media_id' => 'pas-un-uuid'])->assertUnprocessable();
    }

    /** @return list<string> */
    private function sceneOrder(string $projectId): array
    {
        return collect($this->api()->getJson("/api/v1/video/projects/{$projectId}/scenes")->assertOk()->json('data'))->pluck('id')->all();
    }
}
