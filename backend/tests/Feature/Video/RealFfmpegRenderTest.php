<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Domain\Video\VideoRenderService;
use App\Jobs\RenderVideoJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoRenderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Tests d'intégration RÉELS du moteur de rendu (ADR-045) : FFmpeg et FFprobe sont exécutés, un vrai
 * MP4 est produit puis validé. Médias de test générés par FFmpeg (aucun média personnel). Ignorés,
 * avec un message explicite, si les binaires configurés (VIDEO_FFMPEG_BINARY / VIDEO_FFPROBE_BINARY)
 * sont introuvables. Lancer seuls : php artisan test --group=ffmpeg
 */
#[Group('ffmpeg')]
class RealFfmpegRenderTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, mixed> */
    private array $project;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvables (VIDEO_FFMPEG_BINARY, VIDEO_FFPROBE_BINARY) : test d\'intégration réel ignoré.');
        }
        Queue::fake();
        Storage::fake('media');
        $this->temp = storage_path('framework/testing/video-render');
        config(['video.temp_path' => $this->temp]);
        $this->user = User::factory()->create();
        $this->project = $this->api()->postJson('/api/v1/video/projects', ['title' => 'Rendu réel', 'aspect_ratio' => '9:16'])->json('data');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        parent::tearDown();
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    /** Génère un média de test avec FFmpeg, le range dans la médiathèque (disque factice) et le rattache au projet. */
    private function media(string $type, array $ffmpegInput, string $extension, string $mime): array
    {
        $media = Media::factory()->for($this->user)->create(['type' => $type, 'mime_type' => $mime, 'extension' => $extension, 'width' => null, 'height' => null]);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.{$extension}"])->save();
        $target = Storage::disk('media')->path($media->path);
        File::ensureDirectoryExists(dirname($target));
        $process = new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', ...$ffmpegInput, $target]);
        $process->setTimeout(120);
        $process->mustRun();

        $asset = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/assets", ['media_id' => $media->id])->assertCreated()->json('data.id');

        return [$media, $asset];
    }

    private function clip(int $seconds = 6): array
    {
        return $this->media('video', ['-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", '-f', 'lavfi', '-i', "sine=f=440:d={$seconds}", '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest'], 'mp4', 'video/mp4');
    }

    private function track(string $type): string
    {
        return collect($this->project['tracks'])->firstWhere('type', $type)['id'];
    }

    private function add(string $track, array $item): void
    {
        $this->api()->postJson("/api/v1/video/tracks/{$this->track($track)}/items", $item)->assertCreated();
    }

    private function runRender(): VideoRenderJob
    {
        $id = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202)->json('data.id');
        app()->call([new RenderVideoJob($id), 'handle']);

        return VideoRenderJob::findOrFail($id);
    }

    public function test_installed_binaries_are_detected_and_the_renderer_is_available(): void
    {
        $binaries = app(FfmpegBinary::class);

        $this->assertTrue($binaries->isAvailable());
        $this->assertTrue($binaries->isProbeAvailable());
        $this->api()->getJson("/api/v1/video/projects/{$this->project['id']}")->assertJsonPath('meta.renderer_available', true);
    }

    public function test_a_real_mp4_is_rendered_validated_by_ffprobe_and_published(): void
    {
        [, $clip] = $this->clip();
        [, $image] = $this->media('image', ['-f', 'lavfi', '-i', 'testsrc=s=800x800:d=1', '-frames:v', '1'], 'png', 'image/png');
        [, $tone] = $this->media('audio', ['-f', 'lavfi', '-i', 'sine=f=220:d=8', '-c:a', 'aac'], 'm4a', 'audio/mp4');

        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 3000, 'video_project_asset_id' => $clip, 'source_start_ms' => 1000, 'properties' => ['volume' => 80]]);
        $this->add('video', ['type' => 'image', 'start_ms' => 3000, 'duration_ms' => 2000, 'video_project_asset_id' => $image, 'properties' => ['fit' => 'contain'], 'transitions' => ['in' => 'fade', 'duration_ms' => 300]]);
        $this->add('text', ['type' => 'text', 'start_ms' => 500, 'duration_ms' => 2000, 'properties' => ['text' => "Été 2026 : 100% «réel» l'essai", 'position' => 'top', 'font_size' => 72, 'color' => '#FFD700']]);
        $this->add('audio', ['type' => 'audio', 'start_ms' => 1000, 'duration_ms' => 4000, 'video_project_asset_id' => $tone, 'properties' => ['volume' => 40]]);

        $render = $this->runRender();

        $this->assertSame('succeeded', $render->status->value, (string) $render->error_message);
        $this->assertSame(100, $render->progress);
        $this->assertNotNull($render->started_at);
        $this->assertNotNull($render->finished_at);
        $output = Media::findOrFail($render->output_media_id);
        $this->assertSame([$this->user->id, 'video', 'video/mp4', 'ready'], [$output->user_id, $output->type->value, $output->mime_type, $output->status->value]);
        $this->assertSame('video_studio', $output->metadata['source']);
        $this->assertNotNull($output->derivatives['thumbnail']['path'] ?? null, 'Vignette produite.');

        // Le fichier publié est un vrai MP4 : vérification indépendante par FFprobe.
        $probe = app(MediaProbe::class)->probe(Storage::disk('media')->path($output->path));
        $this->assertStringContainsString('mp4', $probe->formatName);
        $this->assertSame(['h264', 'aac', 1080, 1920], [$probe->videoCodec, $probe->audioCodec, $probe->width, $probe->height]);
        $this->assertEqualsWithDelta(5000, $probe->durationMs, 250);
        $this->assertEqualsWithDelta(30.0, $probe->fps, 0.5);
        $this->assertSame($output->size_bytes, Storage::disk('media')->size($output->path));
        $this->assertSame('ready', $render->project->status->value);

        // Résultat exposé par URL signée seulement ; jamais de chemin local ou de stockage.
        $json = $this->api()->getJson("/api/v1/video/renders/{$render->id}")->assertOk()->assertJsonPath('data.output.id', $output->id)->json();
        $this->assertStringContainsString('signature=', $json['data']['output']['url']);
        $this->assertStringNotContainsString(Storage::disk('media')->path(''), json_encode($json));
        $this->assertStringNotContainsString('users/', json_encode($json['data']['output']['url']));
        $file = $this->get($json['data']['output']['url'])->assertOk();
        $this->assertSame('video/mp4', $file->headers->get('Content-Type'));
        $this->get($json['data']['output']['url'], ['Range' => 'bytes=0-99'])->assertStatus(206);

        $this->assertDirectoryDoesNotExist($this->temp.'/'.$render->id, 'Dossier de travail supprimé après succès.');
        $this->assertTrue(Storage::disk('media')->exists(Media::query()->where('type', 'video')->where('id', '!=', $output->id)->firstOrFail()->path), 'Média source intact.');

        // Même timeline : la vidéo existante est renvoyée, aucun nouveau rendu.
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertOk()->assertJsonPath('data.id', $render->id);
    }

    public function test_an_invalid_source_fails_cleanly_and_the_next_render_still_works(): void
    {
        [$media, $asset] = $this->clip();
        Storage::disk('media')->put($media->path, random_bytes(4096)); // fichier corrompu
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 2000, 'video_project_asset_id' => $asset]);

        $failed = $this->runRender();
        $this->assertSame('failed', $failed->status->value);
        // Selon les octets, FFprobe rejette le fichier ou n'y trouve aucune image : échec normalisé dans les deux cas.
        $this->assertContains($failed->error_code, ['PROBE_FAILED', 'SOURCE_INVALID']);
        $this->assertNull($failed->output_media_id);
        $this->assertSame(1, Media::count(), 'Aucun MP4 publié.');
        $this->assertDirectoryDoesNotExist($this->temp.'/'.$failed->id);
        $this->assertSame('draft', $failed->project->status->value);

        // Le moteur reste utilisable : source réparée, nouveau rendu réussi.
        $process = new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=s=320x240:r=25:d=3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', Storage::disk('media')->path($media->path)]);
        $process->mustRun();
        $this->assertSame('succeeded', $this->runRender()->status->value);
    }

    public function test_an_extract_beyond_the_source_duration_is_refused(): void
    {
        [, $asset] = $this->clip(3);
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 2500, 'video_project_asset_id' => $asset, 'source_start_ms' => 2000]);

        $render = $this->runRender();
        $this->assertSame('failed', $render->status->value);
        $this->assertSame('SOURCE_RANGE', $render->error_code);
    }

    public function test_cancellation_really_stops_ffmpeg_and_publishes_nothing(): void
    {
        [, $asset] = $this->clip(30);
        $this->add('video', ['type' => 'video', 'start_ms' => 0, 'duration_ms' => 30_000, 'video_project_asset_id' => $asset]);
        config(['video.encoding.video_preset' => 'medium']); // rendu assez long pour être interrompu
        $id = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202)->json('data.id');
        $renders = app(VideoRenderService::class);
        $render = $renders->start($id);
        $before = $this->ffmpegProcesses();
        $workspace = RenderWorkspace::create($id);
        $started = microtime(true);
        $seen = false;

        try {
            app(FfmpegVideoRenderer::class)->render($render, $workspace,
                // Annulation demandée (comme via l'API) dès que FFmpeg a commencé à encoder.
                onProgress: function () use ($renders, $render, &$seen) {
                    if (! $seen) {
                        $seen = true;
                        $renders->cancel($render->fresh());
                    }
                },
                shouldCancel: fn () => $renders->isCancelled($render),
            );
            $this->fail('Le rendu aurait dû être interrompu.');
        } catch (RenderFailure $e) {
            $this->assertSame('CANCELLED', $e->errorCode);
        } finally {
            $workspace->delete();
        }

        $this->assertTrue($seen, 'FFmpeg avait commencé à encoder.');
        $this->assertLessThan(20, microtime(true) - $started, 'Arrêt rapide, sans attendre la fin de l\'encodage.');
        usleep(500_000);
        $this->assertSame($before, $this->ffmpegProcesses(), 'Aucun processus FFmpeg ne survit à l\'annulation.');
        $this->assertSame('cancelled', VideoRenderJob::findOrFail($id)->status->value);
        $this->assertNull(VideoRenderJob::findOrFail($id)->output_media_id);
        $this->assertDirectoryDoesNotExist($this->temp.'/'.$id);
        $this->assertSame(1, Media::count(), 'Seul le média source existe.');
    }

    /** Nombre de processus ffmpeg en cours sur la machine. */
    private function ffmpegProcesses(): int
    {
        $process = PHP_OS_FAMILY === 'Windows'
            ? new Process(['tasklist', '/FI', 'IMAGENAME eq ffmpeg.exe', '/NH'])
            : new Process(['pgrep', '-c', 'ffmpeg']);
        $process->run();

        return PHP_OS_FAMILY === 'Windows' ? substr_count(strtolower($process->getOutput()), 'ffmpeg.exe') : (int) trim($process->getOutput());
    }
}
