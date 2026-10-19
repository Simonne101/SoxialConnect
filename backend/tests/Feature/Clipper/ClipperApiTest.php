<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Enums\ClipSource;
use App\Domain\Video\Enums\ClipStatus;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * API du YouTube Clipper (ADR-046) : création (lien validé, oEmbed, vidéo de la médiathèque),
 * limite de 60 s dès la requête, isolation des comptes et des types de projet.
 */
class ClipperApiTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

    private User $user;

    private Media $video;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->user = User::factory()->create();
        $this->video = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
    }

    private function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    private function oembed(int $status = 200): void
    {
        Http::fake(['www.youtube.com/oembed*' => Http::response($status === 200 ? ['title' => 'Conférence IA 2026', 'author_name' => 'Chaîne Test'] : ['error' => 'x'], $status)]);
    }

    /** Projet de clipping prêt (analyse terminée), durée de la source : 10 minutes. */
    private function readyProject(?User $user = null): VideoProject
    {
        $user ??= $this->user;
        $project = VideoProject::factory()->for($user)->clipping()->create(['source_media_id' => Media::factory()->for($user)->create(['type' => 'video'])->id]);
        $project->forceFill(['status' => 'ready', 'duration_ms' => 600_000])->save();

        return $project;
    }

    private function suggestion(VideoProject $project, int $start, int $end): VideoClip
    {
        $clip = new VideoClip(['title' => 'Proposé', 'start_ms' => $start, 'end_ms' => $end, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $project->id;
        $clip->forceFill(['source' => ClipSource::Ai, 'status' => ClipStatus::Suggested, 'score' => 80])->save();

        return $clip;
    }

    public function test_a_project_is_created_from_a_youtube_link_and_a_library_video(): void
    {
        $this->oembed();

        $response = $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => 'https://youtu.be/dQw4w9WgXcQ?si=abc', 'media_id' => $this->video->id, 'aspect_ratio' => '9:16'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.title', 'Conférence IA 2026')
            ->assertJsonPath('data.youtube_url', self::URL)
            ->assertJsonPath('data.youtube_video_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.max_clip_ms', 60_000)
            ->assertJsonPath('data.source.id', $this->video->id);

        $this->assertDatabaseHas('video_projects', ['id' => $response->json('data.id'), 'kind' => 'clipping', 'source_type' => 'url', 'source_media_id' => $this->video->id, 'user_id' => $this->user->id]);
        Queue::assertPushed(AnalyzeClipSourceJob::class, fn ($job) => $job->projectId === $response->json('data.id') && $job->connection === 'redis-video' && $job->queue === 'video');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://www.youtube.com/oembed') && $request['url'] === self::URL);
        $this->assertStringNotContainsString('storage', (string) json_encode($response->json()), 'Aucun chemin local exposé.');
    }

    public function test_invalid_links_and_unavailable_videos_are_refused(): void
    {
        $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => 'https://vimeo.com/123456', 'media_id' => $this->video->id])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_URL');
        $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => 'http://youtube.com/watch?v=dQw4w9WgXcQ', 'media_id' => $this->video->id])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_URL');
        $this->api()->postJson('/api/v1/clipper/projects', ['media_id' => $this->video->id])->assertUnprocessable()->assertJsonValidationErrors('youtube_url');

        $this->oembed(404);
        $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'media_id' => $this->video->id])
            ->assertStatus(422)->assertJsonPath('code', 'VIDEO_UNAVAILABLE');

        $this->assertDatabaseCount('video_projects', 0);
        Queue::assertNotPushed(AnalyzeClipSourceJob::class);
    }

    public function test_youtube_unreachable_is_reported_as_temporary(): void
    {
        Http::fake(['www.youtube.com/oembed*' => fn () => throw new ConnectionException('cURL error 28')]);

        $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'media_id' => $this->video->id])
            ->assertStatus(503)->assertJsonPath('code', 'YOUTUBE_UNREACHABLE');
    }

    public function test_the_source_must_be_one_of_my_library_videos(): void
    {
        $this->oembed();
        $foreign = Media::factory()->for(User::factory())->create(['type' => 'video']);
        $image = Media::factory()->for($this->user)->create(['type' => 'image']);
        $processing = Media::factory()->for($this->user)->processing()->create(['type' => 'video']);

        foreach ([$foreign, $image, $processing] as $media) {
            $this->api()->postJson('/api/v1/clipper/projects', ['youtube_url' => self::URL, 'media_id' => $media->id])
                ->assertStatus(422)->assertJsonPath('code', 'SOURCE_INVALID');
        }
        $this->assertDatabaseCount('video_projects', 0);
    }

    public function test_clips_of_thirty_and_sixty_seconds_are_accepted(): void
    {
        $project = $this->readyProject();

        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 120_000, 'end_ms' => 150_000])
            ->assertStatus(202)->assertJsonPath('data.status', 'rendering')->assertJsonPath('data.duration_ms', 30_000)->assertJsonPath('data.source', 'manual');
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 320_000, 'end_ms' => 380_000, 'aspect_ratio' => '1:1', 'title' => 'Une minute'])
            ->assertStatus(202)->assertJsonPath('data.duration_ms', 60_000)->assertJsonPath('data.aspect_ratio', '1:1')->assertJsonPath('data.title', 'Une minute');

        Queue::assertPushed(RenderClipJob::class, 2);
        Queue::assertPushed(RenderClipJob::class, fn ($job) => $job->connection === 'redis-video' && $job->queue === 'video');
    }

    public function test_clips_longer_than_sixty_seconds_are_refused(): void
    {
        $project = $this->readyProject();

        foreach ([[0, 61_000], [600_000 - 120_000, 600_000], [10_000, 10_000 + 60_001]] as [$start, $end]) {
            $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => $start, 'end_ms' => $end])
                ->assertUnprocessable()->assertJsonValidationErrors(['end_ms' => 'Un clip ne peut pas dépasser 60 secondes.']);
        }
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 590_000, 'end_ms' => 620_000])
            ->assertStatus(422)->assertJsonPath('code', 'INVALID_DURATION');

        $this->assertDatabaseCount('video_clips', 0);
        Queue::assertNotPushed(RenderClipJob::class);
    }

    public function test_a_suggestion_can_be_rendered_and_adjusted_within_sixty_seconds(): void
    {
        $project = $this->readyProject();
        $clip = $this->suggestion($project, 30_000, 75_000);

        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render", ['end_ms' => 100_000])
            ->assertUnprocessable()->assertJsonValidationErrors('end_ms');
        $this->assertSame(ClipStatus::Suggested, $clip->fresh()->status);

        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render", ['end_ms' => 90_000])
            ->assertStatus(202)->assertJsonPath('data.id', $clip->id)->assertJsonPath('data.status', 'rendering')->assertJsonPath('data.duration_ms', 60_000);
        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render")
            ->assertStatus(409)->assertJsonPath('code', 'NOT_RENDERABLE');
        Queue::assertPushed(RenderClipJob::class, 1);
    }

    public function test_no_clip_without_a_renderable_source_or_before_it_is_measured(): void
    {
        $withoutSource = VideoProject::factory()->for($this->user)->clipping()->create();
        $this->api()->postJson("/api/v1/clipper/projects/{$withoutSource->id}/clips", ['start_ms' => 0, 'end_ms' => 30_000])
            ->assertStatus(409)->assertJsonPath('code', 'SOURCE_REQUIRED');

        $notMeasured = VideoProject::factory()->for($this->user)->clipping()->create(['source_media_id' => $this->video->id]);
        $this->api()->postJson("/api/v1/clipper/projects/{$notMeasured->id}/clips", ['start_ms' => 0, 'end_ms' => 30_000])
            ->assertStatus(409)->assertJsonPath('code', 'NOT_READY');
        Queue::assertNotPushed(RenderClipJob::class);
    }

    public function test_projects_and_clips_of_other_accounts_and_other_types_are_not_found(): void
    {
        $other = User::factory()->create();
        $foreignProject = $this->readyProject($other);
        $foreignClip = $this->suggestion($foreignProject, 0, 30_000);
        $composition = VideoProject::factory()->for($this->user)->create();

        $this->api()->getJson("/api/v1/clipper/projects/{$foreignProject->id}")->assertNotFound();
        $this->api()->deleteJson("/api/v1/clipper/projects/{$foreignProject->id}")->assertNotFound();
        $this->api()->postJson("/api/v1/clipper/projects/{$foreignProject->id}/clips", ['start_ms' => 0, 'end_ms' => 30_000])->assertNotFound();
        $this->api()->postJson("/api/v1/clipper/clips/{$foreignClip->id}/render")->assertNotFound();
        $this->api()->getJson("/api/v1/clipper/projects/{$composition->id}")->assertNotFound();
        $this->api()->getJson('/api/v1/clipper/projects')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame(ClipStatus::Suggested, $foreignClip->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_a_project_shows_its_suggestions_and_can_be_deleted(): void
    {
        $project = $this->readyProject();
        $this->suggestion($project, 0, 30_000);

        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")
            ->assertOk()->assertJsonPath('data.status', 'ready')->assertJsonCount(1, 'data.clips')
            ->assertJsonPath('data.clips.0.status', 'suggested')->assertJsonPath('data.clips.0.duration_ms', 30_000)
            ->assertJsonStructure(['meta' => ['renderer_available', 'analysis_available']]);
        $this->api()->getJson('/api/v1/clipper/projects')->assertOk()->assertJsonCount(1, 'data');

        $this->api()->deleteJson("/api/v1/clipper/projects/{$project->id}")->assertOk();
        $this->assertSoftDeleted('video_projects', ['id' => $project->id]);
    }

    public function test_the_database_itself_refuses_a_clip_longer_than_sixty_seconds(): void
    {
        $project = $this->readyProject();
        $this->suggestion($project, 0, 60_000);

        $this->expectException(QueryException::class);
        $this->suggestion($project, 0, 60_001);
    }

    public function test_the_source_video_cannot_be_deleted_while_it_is_being_analyzed(): void
    {
        $project = VideoProject::factory()->for($this->user)->clipping()->create(['source_media_id' => $this->video->id]);

        $this->api()->deleteJson("/api/v1/media/{$this->video->id}")->assertStatus(409);
        $project->forceFill(['status' => 'ready', 'duration_ms' => 600_000])->save();
        $this->api()->deleteJson("/api/v1/media/{$this->video->id}")->assertNoContent();
    }
}
