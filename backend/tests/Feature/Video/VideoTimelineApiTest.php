<?php

namespace Tests\Feature\Video;

use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Timeline Video Studio (ADR-044) : éléments posés sur les pistes, temps en millisecondes ;
 * déplacement et redimensionnement par un seul PATCH ; cohérence temporelle contrôlée par le serveur.
 */
class VideoTimelineApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, mixed> */
    private array $project;

    /** @var array<string, string> id des pistes initiales par type */
    private array $tracks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = $this->api()->postJson('/api/v1/video/projects', ['title' => 'Timeline'])->assertCreated()->json('data');
        $this->tracks = collect($this->project['tracks'])->pluck('id', 'type')->all();
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    /** Rattache un média du type donné au projet ; renvoie l'id de l'asset. */
    private function asset(string $type, ?int $durationMs = null): string
    {
        $formats = ['video' => ['video/mp4', 'mp4'], 'audio' => ['audio/mpeg', 'mp3'], 'image' => ['image/jpeg', 'jpg']];
        $media = Media::factory()->for($this->user)->create(['type' => $type, 'mime_type' => $formats[$type][0], 'extension' => $formats[$type][1], 'duration_ms' => $durationMs]);

        return $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/assets", ['media_id' => $media->id])->assertCreated()->json('data.id');
    }

    private function add(string $track, array $item)
    {
        return $this->api()->postJson("/api/v1/video/tracks/{$this->tracks[$track]}/items", $item);
    }

    public function test_a_timeline_is_built_saved_and_reloaded_across_tracks(): void
    {
        $clip = $this->asset('video', 20_000);
        $music = $this->asset('audio', 60_000);

        $a = $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 4000, 'video_project_asset_id' => $clip, 'source_start_ms' => 1000, 'properties' => ['volume' => 50, 'fit' => 'cover']])
            ->assertCreated()->assertJsonPath('data.end_ms', 4000)->assertJsonPath('meta.duration_ms', 4000)->json('data.id');
        $this->add('video', ['type' => 'image', 'start_ms' => 4000, 'duration_ms' => 3000, 'video_project_asset_id' => $this->asset('image'), 'transitions' => ['in' => 'fade', 'duration_ms' => 300]])->assertCreated();
        $this->add('text', ['type' => 'text', 'start_ms' => 500, 'duration_ms' => 2000, 'properties' => ['text' => 'Bienvenue', 'position' => 'bottom', 'font_size' => 64, 'color' => '#FFFFFF']])->assertCreated();
        $this->add('audio', ['type' => 'audio', 'start_ms' => 0, 'duration_ms' => 7000, 'video_project_asset_id' => $music, 'properties' => ['volume' => 30]])
            ->assertCreated()->assertJsonPath('meta.duration_ms', 7000);

        // Rechargement : timeline complète, éléments dans l'ordre du temps, durée du projet à jour.
        $timeline = $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}/timeline")->assertOk()->json('data');
        $this->assertSame(7000, $timeline['duration_ms']);
        $this->assertSame(['video', 'text', 'audio'], array_column($timeline['tracks'], 'type'));
        $this->assertSame(['video', 'image'], array_column($timeline['tracks'][0]['items'], 'type'));
        $this->assertSame([$a], [$timeline['tracks'][0]['items'][0]['id']]);
        $this->assertSame('Bienvenue', $timeline['tracks'][1]['items'][0]['properties']['text']);
        $this->assertSame(1000, $timeline['tracks'][0]['items'][0]['source_start_ms']);

        $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}")->assertOk()
            ->assertJsonPath('data.duration_ms', 7000)->assertJsonCount(2, 'data.tracks.0.items')->assertJsonCount(3, 'data.assets');
        $this->assertSame(7000, VideoProject::sole()->duration_ms);
    }

    public function test_items_are_moved_resized_and_deleted_with_a_single_patch(): void
    {
        $item = $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 2000, 'properties' => ['text' => 'A']])->json('data.id');

        // Déplacer.
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['start_ms' => 5000])->assertOk()
            ->assertJsonPath('data.start_ms', 5000)->assertJsonPath('data.end_ms', 7000)->assertJsonPath('meta.duration_ms', 7000);
        // Redimensionner.
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['duration_ms' => 500])->assertOk()->assertJsonPath('meta.duration_ms', 5500);
        // Contenu.
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['properties' => ['text' => 'B', 'align' => 'left']])->assertOk()->assertJsonPath('data.properties.text', 'B');
        // Changer de piste (piste compatible du même projet).
        $overlay = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/tracks", ['type' => 'overlay'])->json('data.id');
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['video_track_id' => $overlay])->assertOk()->assertJsonPath('data.video_track_id', $overlay);
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['video_track_id' => $this->tracks['audio']])
            ->assertUnprocessable()->assertJsonValidationErrors('type');
        // Le type ne change pas après création.
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['type' => 'audio'])->assertUnprocessable()->assertJsonValidationErrors('type');

        $this->api()->deleteJson("/api/v1/video/timeline-items/{$item}")->assertOk()->assertJsonPath('meta.duration_ms', 0);
        $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}/timeline")->assertJsonPath('data.duration_ms', 0);
    }

    public function test_temporal_coherence_is_enforced_by_the_server(): void
    {
        $this->add('text', ['type' => 'text', 'start_ms' => 1000, 'duration_ms' => 2000, 'properties' => ['text' => 'A']])->assertCreated();
        $text = fn (array $item) => $this->add('text', $item + ['type' => 'text', 'properties' => ['text' => 'B']]);

        $text(['start_ms' => 2000, 'duration_ms' => 2000])->assertUnprocessable()->assertJsonValidationErrors('start_ms'); // chevauchement
        $text(['start_ms' => 0, 'duration_ms' => 1500])->assertUnprocessable()->assertJsonValidationErrors('start_ms');
        $text(['start_ms' => 3000, 'duration_ms' => 1000])->assertCreated(); // bout à bout : accepté
        $text(['start_ms' => 0, 'duration_ms' => 1000])->assertCreated();
        $text(['start_ms' => -1, 'duration_ms' => 1000])->assertUnprocessable()->assertJsonValidationErrors('start_ms');
        $text(['start_ms' => 5000, 'duration_ms' => 0])->assertUnprocessable()->assertJsonValidationErrors('duration_ms');
        $text(['start_ms' => 5000, 'duration_ms' => 50])->assertUnprocessable()->assertJsonValidationErrors('duration_ms'); // < 100 ms
        $text(['start_ms' => 89_000, 'duration_ms' => 2000])->assertUnprocessable()->assertJsonValidationErrors('duration_ms'); // > 90 s
        $text(['start_ms' => '1.5', 'duration_ms' => 1000])->assertUnprocessable()->assertJsonValidationErrors('start_ms'); // ms entières
    }

    public function test_assets_types_and_source_ranges_are_checked(): void
    {
        $clip = $this->asset('video', 10_000);
        $image = $this->asset('image');

        // Type d'élément refusé par la piste ; asset d'un autre type ; texte sans contenu ou avec média.
        $this->add('audio', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 1000, 'video_project_asset_id' => $clip])->assertUnprocessable()->assertJsonValidationErrors('type');
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 1000, 'video_project_asset_id' => $image])->assertUnprocessable()->assertJsonValidationErrors('video_project_asset_id');
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 1000])->assertUnprocessable()->assertJsonValidationErrors('video_project_asset_id');
        $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000])->assertUnprocessable()->assertJsonValidationErrors('properties.text');
        $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'video_project_asset_id' => $image, 'properties' => ['text' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('video_project_asset_id');
        $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'properties' => ['text' => 'x', 'script' => '<b>']])->assertUnprocessable()->assertJsonValidationErrors('properties');
        $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'properties' => ['text' => 'x', 'color' => 'red']])->assertUnprocessable()->assertJsonValidationErrors('properties.color');

        // Extrait : dans la durée du média source ; uniquement vidéo et audio.
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 4000, 'video_project_asset_id' => $clip, 'source_start_ms' => 8000])->assertUnprocessable()->assertJsonValidationErrors('source_start_ms');
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 4000, 'video_project_asset_id' => $clip, 'source_start_ms' => 6000])->assertCreated();
        $this->add('video', ['type' => 'image', 'start_ms' => 5000, 'duration_ms' => 1000, 'video_project_asset_id' => $image, 'source_start_ms' => 0])->assertUnprocessable()->assertJsonValidationErrors('source_start_ms');
    }

    public function test_assets_used_on_the_timeline_cannot_be_removed_and_unavailable_media_are_refused(): void
    {
        $clip = $this->asset('video');
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 1000, 'video_project_asset_id' => $clip])->assertCreated();
        $this->api()->deleteJson("/api/v1/video/assets/{$clip}")->assertStatus(409)->assertJsonPath('code', 'ASSET_IN_USE');

        // Média supprimé de la médiathèque : l'asset reste, marqué indisponible, et n'est plus utilisable.
        $image = $this->asset('image');
        $this->api()->deleteJson('/api/v1/media/'.$this->mediaIdOf($image))->assertNoContent();
        $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}/assets")->assertOk()->assertJsonFragment(['id' => $image, 'available' => false]);
        $this->add('video', ['type' => 'image', 'start_ms' => 2000, 'duration_ms' => 1000, 'video_project_asset_id' => $image])->assertUnprocessable()->assertJsonValidationErrors('video_project_asset_id');
    }

    public function test_locked_tracks_and_stale_versions_are_refused(): void
    {
        $item = $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'properties' => ['text' => 'A']])->json('data.id');
        $this->api()->patchJson("/api/v1/video/tracks/{$this->tracks['text']}", ['locked' => true])->assertOk();

        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['start_ms' => 2000])->assertStatus(409)->assertJsonPath('code', 'TRACK_LOCKED');
        $this->api()->deleteJson("/api/v1/video/timeline-items/{$item}")->assertStatus(409);
        $this->add('text', ['type' => 'text', 'start_ms' => 3000, 'duration_ms' => 1000, 'properties' => ['text' => 'B']])->assertStatus(409);

        $version = VideoProject::sole()->lock_version;
        $this->api()->patchJson("/api/v1/video/tracks/{$this->tracks['text']}", ['locked' => false, 'lock_version' => $version - 1])->assertStatus(409)->assertJsonPath('code', 'STALE_VERSION');
        $this->api()->patchJson("/api/v1/video/tracks/{$this->tracks['text']}", ['locked' => false, 'lock_version' => $version])->assertOk();

        // Type de piste non modifiable tant qu'elle contient des éléments.
        $this->api()->patchJson("/api/v1/video/tracks/{$this->tracks['text']}", ['type' => 'overlay'])->assertStatus(409)->assertJsonPath('code', 'TRACK_NOT_EMPTY');
    }

    public function test_deleting_a_track_removes_its_items_and_deleting_a_scene_only_detaches_them(): void
    {
        $scene = $this->project['scenes'][0]['id'];
        $text = $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 4000, 'video_scene_id' => $scene, 'properties' => ['text' => 'A']])
            ->assertCreated()->assertJsonPath('data.video_scene_id', $scene)->json('data.id');

        $this->api()->deleteJson("/api/v1/video/scenes/{$scene}")->assertOk();
        $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}/timeline")->assertJsonPath('data.tracks.1.items.0.id', $text)->assertJsonPath('data.tracks.1.items.0.video_scene_id', null);

        $this->api()->deleteJson("/api/v1/video/tracks/{$this->tracks['text']}")->assertOk()->assertJsonPath('meta.duration_ms', 0);
        $this->assertDatabaseMissing('video_timeline_items', ['id' => $text]);
    }

    public function test_item_limit_per_track(): void
    {
        config(['video.limits.max_items_per_track' => 1]);
        $this->add('text', ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'properties' => ['text' => 'A']])->assertCreated();
        $this->add('text', ['type' => 'text', 'start_ms' => 2000, 'duration_ms' => 1000, 'properties' => ['text' => 'B']])->assertStatus(409)->assertJsonPath('code', 'LIMIT_REACHED');
    }

    private function mediaIdOf(string $assetId): string
    {
        return collect($this->api()->getJson("/api/v1/video/projects/{$this->project['id']}/assets")->json('data'))->firstWhere('id', $assetId)['media_id'];
    }
}
