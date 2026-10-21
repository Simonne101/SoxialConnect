<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Rendering\AudioCheck;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Son des clips (incident du 2026-10-13 : volume transmis à 1 au lieu de 100 → son atténué de 40 dB, clips
 * inaudibles mais « réussis »). RÉEL : vidéo source générée par FFmpeg (son à niveau connu), découpe FFmpeg,
 * FFprobe, mesure du niveau (volumedetect), publication dans la médiathèque, fichier téléchargé.
 */
class ClipAudioTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvables : test réel ignoré.');
        }
        Queue::fake();
        Storage::fake('media');
        $this->temp = storage_path('framework/testing/clip-audio');
        config(['video.temp_path' => $this->temp, 'video.clipper.composer' => 'ffmpeg']);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        parent::tearDown();
    }

    public function test_the_published_and_downloaded_clip_keeps_the_original_sound_level(): void
    {
        $project = $this->project('sine=f=440:d=12');
        $source = Storage::disk('media')->path(Media::findOrFail($project->source_media_id)->path);
        $clip = $this->render($project, 2_000, 8_000);

        $this->assertSame(ClipStatus::Rendered, $clip->status, (string) $clip->error_message);
        $output = Media::findOrFail($clip->output_media_id);
        $this->assertSame('ok', $output->metadata['audio']['status']);

        // Fichier réellement servi au téléchargement : flux audio présent, durée cohérente, niveau de la source.
        $url = $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertOk()->json('data.clips.0.output.download_url');
        $response = $this->api()->get((string) $url)->assertOk()->assertHeader('Content-Type', 'video/mp4');
        $downloaded = $this->temp.'/telecharge.mp4';
        File::copy($response->baseResponse->getFile()->getPathname(), $downloaded);
        $checks = app(AudioCheck::class);
        $streams = $checks->streamDurations($downloaded);
        $this->assertNotNull($streams['audio'], 'Piste audio présente dans le fichier téléchargé.');
        $this->assertEqualsWithDelta($streams['video'], $streams['audio'], 250, 'Son et image synchronisés.');
        $reference = $checks->loudness($source, 2_000, 6_000);
        $final = $checks->loudness($downloaded);
        $this->assertEqualsWithDelta($reference['mean'], $final['mean'], 3.0, 'Niveau du clip identique à celui de la source (et non −40 dB).');
        $this->assertGreaterThan(-30.0, $final['max']);
    }

    public function test_a_clip_whose_sound_is_lost_by_processing_fails_and_is_never_published(): void
    {
        // Traitement défaillant simulé : le fichier produit est mesuré silencieux alors que la source a du son.
        $this->app->instance(AudioCheck::class, new class(app(FfmpegBinary::class)) extends AudioCheck
        {
            public function loudness(string $path, ?int $startMs = null, ?int $durationMs = null): ?array
            {
                return $startMs === null ? ['mean' => -91.0, 'max' => -90.0] : parent::loudness($path, $startMs, $durationMs);
            }
        });
        $clip = $this->render($this->project('sine=f=440:d=12'), 2_000, 8_000);

        $this->assertSame([ClipStatus::Failed, 'AUDIO_SILENT'], [$clip->status, $clip->error_code]);
        $this->assertStringContainsString('il n\'a pas été publié', (string) $clip->error_message);
        $this->assertNull($clip->output_media_id);
        $this->assertSame(0, Media::query()->where('metadata->source', 'youtube_clipper')->count());
    }

    public function test_a_silent_source_passage_or_a_source_without_audio_is_published_and_flagged(): void
    {
        $silent = $this->render($this->project('anullsrc=r=48000:cl=stereo:d=12'), 2_000, 8_000);
        $this->assertSame(ClipStatus::Rendered, $silent->status, (string) $silent->error_message);
        $this->assertSame('source_silent', Media::findOrFail($silent->output_media_id)->metadata['audio']['status']);

        $mute = $this->render($this->project(null), 2_000, 8_000);
        $this->assertSame(ClipStatus::Rendered, $mute->status, (string) $mute->error_message);
        $this->assertSame('source_without_audio', Media::findOrFail($mute->output_media_id)->metadata['audio']['status']);
        $this->api()->getJson("/api/v1/clipper/projects/{$mute->video_project_id}")->assertOk()
            ->assertJsonPath('data.clips.0.render.audio', 'source_without_audio');
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    /** Projet prêt dont la source (12 s) a le son demandé (filtre lavfi), ou aucune piste audio (null). */
    private function project(?string $audio): VideoProject
    {
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'width' => 640, 'height' => 360]);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.mp4"])->save();
        $target = Storage::disk('media')->path($media->path);
        File::ensureDirectoryExists(dirname($target));
        $input = $audio !== null ? ['-f', 'lavfi', '-i', $audio] : [];
        $codec = $audio !== null ? ['-c:a', 'aac', '-shortest'] : ['-an'];
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc2=s=640x360:r=25:d=12', ...$input,
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', ...$codec, $target]))->setTimeout(180)->mustRun();

        return VideoProject::factory()->for($this->user)->clipping()->create([
            'source_media_id' => $media->id, 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Son', 'status' => 'ready', 'duration_ms' => 12_000,
        ]);
    }

    private function render(VideoProject $project, int $start, int $end): VideoClip
    {
        $id = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => $start, 'end_ms' => $end, 'aspect_ratio' => '9:16'])->assertStatus(202)->json('data.id');
        app()->call([new RenderClipJob($id), 'handle']);

        return VideoClip::findOrFail($id);
    }
}
