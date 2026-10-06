<?php

namespace Tests\Feature\Video;

use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use App\Models\VideoRenderJob;
use App\Models\VideoScene;
use App\Models\VideoTimelineItem;
use App\Models\VideoTrack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Isolation Video Studio (ADR-044) : User A → projet A = OK ; User A → projet B = introuvable (404),
 * sur CHAQUE endpoint, sans rien lire ni modifier. Invités : 401. Adresse non vérifiée : 403.
 */
class VideoOwnershipApiTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    /** @var array<string, string> ressources de Bob */
    private array $bob_;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();

        $project = VideoProject::factory()->for($this->bob)->create();
        $track = VideoTrack::factory()->for($project, 'project')->type('text')->create(['position' => 0]);
        $this->bob_ = [
            'project' => $project->id,
            'scene' => VideoScene::factory()->for($project, 'project')->create(['position' => 0])->id,
            'track' => $track->id,
            'item' => VideoTimelineItem::factory()->for($track, 'track')->create()->id,
            'asset' => VideoProjectAsset::factory()->for($project, 'project')->create()->id,
            'render' => VideoRenderJob::factory()->for($project, 'project')->queued()->create()->id,
            'media' => Media::factory()->for($this->bob)->create()->id,
        ];
    }

    /** @return list<array{0: string, 1: string, 2?: array<string, mixed>}> tous les endpoints vidéo visant les ressources de Bob */
    private function bobsEndpoints(): array
    {
        $b = $this->bob_;

        return [
            ['get', "/api/v1/video/projects/{$b['project']}"],
            ['patch', "/api/v1/video/projects/{$b['project']}", ['title' => 'Piraté']],
            ['delete', "/api/v1/video/projects/{$b['project']}"],
            ['get', "/api/v1/video/projects/{$b['project']}/scenes"],
            ['post', "/api/v1/video/projects/{$b['project']}/scenes", ['duration_ms' => 1000]],
            ['patch', "/api/v1/video/scenes/{$b['scene']}", ['duration_ms' => 1000]],
            ['delete', "/api/v1/video/scenes/{$b['scene']}"],
            ['get', "/api/v1/video/projects/{$b['project']}/tracks"],
            ['post', "/api/v1/video/projects/{$b['project']}/tracks", ['type' => 'text']],
            ['patch', "/api/v1/video/tracks/{$b['track']}", ['name' => 'Piraté']],
            ['delete', "/api/v1/video/tracks/{$b['track']}"],
            ['get', "/api/v1/video/projects/{$b['project']}/timeline"],
            ['post', "/api/v1/video/tracks/{$b['track']}/items", ['type' => 'text', 'start_ms' => 5000, 'duration_ms' => 1000, 'properties' => ['text' => 'x']]],
            ['patch', "/api/v1/video/timeline-items/{$b['item']}", ['start_ms' => 9000]],
            ['delete', "/api/v1/video/timeline-items/{$b['item']}"],
            ['get', "/api/v1/video/projects/{$b['project']}/assets"],
            ['post', "/api/v1/video/projects/{$b['project']}/assets", ['media_id' => $b['media']]],
            ['delete', "/api/v1/video/assets/{$b['asset']}"],
            ['get', "/api/v1/video/projects/{$b['project']}/renders"],
            ['post', "/api/v1/video/projects/{$b['project']}/renders"],
            ['get', "/api/v1/video/renders/{$b['render']}"],
            ['post', "/api/v1/video/renders/{$b['render']}/cancel"],
        ];
    }

    public function test_user_a_cannot_reach_any_resource_of_user_b(): void
    {
        $before = $this->snapshot();

        foreach ($this->bobsEndpoints() as $endpoint) {
            [$method, $uri] = $endpoint;
            $response = $this->actingAs($this->alice)->fromSpa()->json($method, $uri, $endpoint[2] ?? []);
            // Jamais 200, 403 ou 422 : l'existence de la ressource n'est pas révélée.
            $this->assertSame(404, $response->status(), strtoupper($method).' '.$uri);
        }

        $this->assertSame($before, $this->snapshot(), 'Aucune ressource de Bob lue ou modifiée.');
        Queue::assertNothingPushed();
    }

    public function test_user_b_reaches_the_same_resources(): void
    {
        foreach (["/api/v1/video/projects/{$this->bob_['project']}", "/api/v1/video/renders/{$this->bob_['render']}"] as $uri) {
            $this->actingAs($this->bob)->fromSpa()->getJson($uri)->assertOk();
        }
        $this->actingAs($this->bob)->fromSpa()->patchJson("/api/v1/video/scenes/{$this->bob_['scene']}", ['name' => 'OK'])->assertOk();
    }

    public function test_user_a_cannot_reference_user_b_resources_from_its_own_project(): void
    {
        $mine = $this->actingAs($this->alice)->fromSpa()->postJson('/api/v1/video/projects', ['title' => 'A'])->json('data');
        $myTrack = collect($mine['tracks'])->firstWhere('type', 'video')['id'];
        $myText = collect($mine['tracks'])->firstWhere('type', 'text')['id'];
        $api = fn () => $this->actingAs($this->alice)->fromSpa();

        // Média, asset, scène et piste de Bob : refusés comme introuvables.
        $api()->postJson("/api/v1/video/projects/{$mine['id']}/assets", ['media_id' => $this->bob_['media']])->assertUnprocessable()->assertJsonValidationErrors('media_id');
        $api()->postJson("/api/v1/video/tracks/{$myTrack}/items", ['type' => 'image', 'start_ms' => 0, 'duration_ms' => 1000, 'video_project_asset_id' => $this->bob_['asset']])
            ->assertUnprocessable()->assertJsonValidationErrors('video_project_asset_id');
        $api()->postJson("/api/v1/video/tracks/{$myText}/items", ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'video_scene_id' => $this->bob_['scene'], 'properties' => ['text' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors('video_scene_id');
        $item = $api()->postJson("/api/v1/video/tracks/{$myText}/items", ['type' => 'text', 'start_ms' => 0, 'duration_ms' => 1000, 'properties' => ['text' => 'x']])->json('data.id');
        $api()->patchJson("/api/v1/video/timeline-items/{$item}", ['video_track_id' => $this->bob_['track']])->assertUnprocessable()->assertJsonValidationErrors('video_track_id');
        $api()->patchJson("/api/v1/video/scenes/{$mine['scenes'][0]['id']}", ['media_id' => $this->bob_['media']])->assertUnprocessable()->assertJsonValidationErrors('media_id');

        $this->assertSame(0, VideoTimelineItem::query()->where('video_track_id', $this->bob_['track'])->where('properties->text', 'x')->count());
    }

    public function test_guests_and_unverified_users_are_refused(): void
    {
        $this->fromSpa()->getJson('/api/v1/video/projects')->assertUnauthorized();
        $this->fromSpa()->postJson('/api/v1/video/projects', ['title' => 'x'])->assertUnauthorized();
        $this->actingAs(User::factory()->unverified()->create())->fromSpa()->getJson('/api/v1/video/projects')->assertForbidden();
    }

    /** @return array<string, mixed> état complet des ressources de Bob */
    private function snapshot(): array
    {
        $project = VideoProject::withTrashed()->with(['scenes', 'tracks.items', 'assets', 'renderJobs'])->findOrFail($this->bob_['project']);

        return $project->toArray();
    }
}
