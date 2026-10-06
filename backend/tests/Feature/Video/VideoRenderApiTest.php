<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\VideoRenderService;
use App\Jobs\RenderVideoJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRenderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Rendus Video Studio (ADR-044) : la demande fige la timeline et planifie RenderVideoJob ; aucun rendu
 * n'est exécuté (FFmpeg non installé, moteur prévu pour la phase Rendu) et aucun fichier n'est produit.
 */
class VideoRenderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, mixed> */
    private array $project;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('media');
        $this->user = User::factory()->create();
        $this->project = $this->api()->postJson('/api/v1/video/projects', ['title' => 'Rendu', 'aspect_ratio' => '1:1'])->json('data');
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    /** Pose un élément vidéo (média du projet) sur la piste vidéo ; renvoie le média. */
    private function withVideoItem(): Media
    {
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $asset = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/assets", ['media_id' => $media->id])->json('data.id');
        $track = collect($this->project['tracks'])->firstWhere('type', 'video')['id'];
        $this->api()->postJson("/api/v1/video/tracks/{$track}/items", ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 3000, 'video_project_asset_id' => $asset])->assertCreated();

        return $media;
    }

    private function fakeFfmpeg(bool $available): void
    {
        $this->app->instance(FfmpegBinary::class, new class($available) extends FfmpegBinary
        {
            public function __construct(private readonly bool $available) {}

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function isProbeAvailable(): bool
            {
                return $this->available;
            }
        });
    }

    public function test_a_render_request_freezes_the_timeline_and_queues_the_job_on_the_video_connection(): void
    {
        $media = $this->withVideoItem();
        $this->fakeFfmpeg(false);

        $render = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.aspect_ratio', '1:1')
            ->assertJsonPath('data.width', 1080)
            ->assertJsonPath('data.height', 1080)
            ->assertJsonPath('data.fps', 30)
            ->assertJsonPath('data.output_media_id', null)
            ->assertJsonPath('meta.renderer_available', false)
            ->assertJsonMissingPath('data.spec')
            ->assertJsonPath('message', 'Rendu planifié, mais FFmpeg n\'est pas installé sur le serveur : il ne pourra pas être produit pour le moment.')
            ->json('data');
        $this->assertSame('queued', VideoRenderJob::findOrFail($render['id'])->status->value);

        $stored = VideoRenderJob::sole();
        $this->assertSame($this->user->id, $stored->user_id);
        $this->assertSame('soxial.render_spec.v1', $stored->spec['schema']);
        $this->assertSame($media->id, $stored->spec['tracks'][0]['items'][0]['media_id']);
        $this->assertSame(VideoRenderService::hashSpec($stored->spec), $stored->spec_hash, 'Empreinte reproductible depuis la spécification stockée.');
        $this->assertStringNotContainsString('storage', json_encode($stored->spec), 'Identifiants seulement, jamais de chemin de fichier.');
        $this->assertSame('rendering', VideoProject::sole()->status->value);
        $this->assertSame($stored->id, VideoProject::sole()->last_render_id);

        Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => $job->renderId === $stored->id && $job->connection === 'redis-video' && $job->queue === 'video');
    }

    public function test_without_ffmpeg_the_job_fails_cleanly_and_produces_no_file(): void
    {
        $this->withVideoItem();
        $this->fakeFfmpeg(false);
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202);
        $mediaBefore = Media::count();

        app()->call([new RenderVideoJob(VideoRenderJob::sole()->id), 'handle']);

        $render = VideoRenderJob::sole();
        $this->assertSame('failed', $render->status->value);
        $this->assertSame('RENDERER_UNAVAILABLE', $render->error_code);
        $this->assertNotNull($render->finished_at);
        $this->assertNull($render->output_media_id);
        $this->assertSame($mediaBefore, Media::count(), 'Aucun MP4 simulé.');
        $this->assertSame([], Storage::disk('media')->allFiles());
        $this->assertSame('draft', VideoProject::sole()->status->value, 'Projet de nouveau modifiable.');

        // Un nouveau rendu peut être demandé après l'échec.
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202);
    }

    public function test_a_missing_source_file_fails_cleanly_without_publishing_anything(): void
    {
        $this->withVideoItem(); // média enregistré, mais aucun fichier dans le stockage
        $this->fakeFfmpeg(true);
        config(['video.temp_path' => storage_path('framework/testing/video-render')]);
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202)->assertJsonPath('meta.renderer_available', true);
        $mediaBefore = Media::count();

        app()->call([new RenderVideoJob(VideoRenderJob::sole()->id), 'handle']);

        $render = VideoRenderJob::sole();
        $this->assertSame('failed', $render->status->value);
        $this->assertSame('SOURCE_MISSING', $render->error_code);
        $this->assertStringNotContainsString(':\\', (string) $render->error_message, 'Aucun chemin de fichier dans le message.');
        $this->assertNull($render->output_media_id);
        $this->assertSame($mediaBefore, Media::count());
        $this->assertDirectoryDoesNotExist(storage_path('framework/testing/video-render/'.$render->id), 'Dossier de travail supprimé.');
    }

    public function test_ffmpeg_absence_is_detected_without_installing_anything(): void
    {
        config(['video.binaries.ffmpeg' => 'soxial-ffmpeg-introuvable-'.uniqid()]);
        Cache::flush();

        $this->assertFalse(app(FfmpegBinary::class)->isAvailable());
    }

    public function test_renders_are_refused_on_an_empty_timeline_or_while_another_is_active(): void
    {
        $this->fakeFfmpeg(false);
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(409)->assertJsonPath('code', 'EMPTY_TIMELINE');

        $this->withVideoItem();
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202);
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(409)->assertJsonPath('code', 'RENDER_ACTIVE');
        $this->assertSame(1, VideoRenderJob::count());
        Queue::assertPushed(RenderVideoJob::class, 1);
    }

    public function test_renders_are_listed_read_and_cancelled(): void
    {
        $this->withVideoItem();
        $this->fakeFfmpeg(false);
        $id = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->json('data.id');

        $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('meta.renderer_available', false);
        $this->api()->getJson("/api/v1/video/renders/{$id}")->assertOk()->assertJsonPath('data.status', 'queued');

        $this->api()->postJson("/api/v1/video/renders/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('draft', VideoProject::sole()->status->value);
        $this->api()->postJson("/api/v1/video/renders/{$id}/cancel")->assertStatus(409)->assertJsonPath('code', 'NOT_CANCELLABLE');

        // Le job d'un rendu annulé ne fait rien.
        app()->call([new RenderVideoJob($id), 'handle']);
        $this->assertSame('cancelled', VideoRenderJob::sole()->status->value);
    }

    public function test_project_and_media_cannot_be_deleted_while_a_render_is_active(): void
    {
        $media = $this->withVideoItem();
        $this->fakeFfmpeg(false);
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202);

        $this->api()->deleteJson("/api/v1/video/projects/{$this->project['id']}")->assertStatus(409)->assertJsonPath('code', 'RENDER_ACTIVE');
        $this->api()->deleteJson("/api/v1/media/{$media->id}")->assertStatus(409);
        $this->assertNotSoftDeleted($media);

        // Rendu terminé : le média redevient supprimable (le projet le signalera indisponible).
        app()->call([new RenderVideoJob(VideoRenderJob::sole()->id), 'handle']);
        $this->api()->deleteJson("/api/v1/media/{$media->id}")->assertNoContent();
        $this->api()->deleteJson("/api/v1/video/projects/{$this->project['id']}")->assertNoContent();
    }

    public function test_a_render_with_unavailable_media_is_refused(): void
    {
        $media = $this->withVideoItem();
        $media->delete();

        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(409)->assertJsonPath('code', 'MEDIA_UNAVAILABLE');
        Queue::assertNothingPushed();
    }
}
