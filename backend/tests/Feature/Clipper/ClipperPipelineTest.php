<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\FfmpegCommandBuilder;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Rendering\ProbeResult;
use App\Domain\Video\Rendering\RenderedVideo;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Jobs\AnalyzeClipSourceJob;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Chaîne RÉELLE du YouTube Clipper (ADR-046) : vidéo source générée par FFmpeg (aucun téléchargement
 * YouTube), analyse (FFprobe + extraction audio réelles ; transcription et choix des passages par
 * des réponses OpenAI simulées), puis clips rendus par le moteur FFmpeg existant et relus par FFprobe.
 * Ignorés si FFmpeg/FFprobe sont introuvables. Lancer seuls : php artisan test --group=ffmpeg
 */
#[Group('ffmpeg')]
class ClipperPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_SECONDS = 90;

    private User $user;

    private Media $source;

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
        Http::preventStrayRequests();
        $this->temp = storage_path('framework/testing/clipper');
        config([
            'video.temp_path' => $this->temp,
            'ai.default_provider' => 'openai',
            'ai.providers.openai.api_key' => 'sk-test-cle-fictive-ne-doit-jamais-sortir',
            'ai.tasks.transcription.model' => 'transcription-test',
            'ai.tasks.video_analysis.model' => 'analyse-test',
            // Transcription par le fournisseur IA simulé (whisper.cpp local est testé à part).
            'video.clipper.transcription' => 'ai',
        ]);
        $this->user = User::factory()->create();
        $this->source = $this->sourceVideo(self::SOURCE_SECONDS);
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

    /** Vidéo de test (image + son) générée par FFmpeg et rangée dans la médiathèque factice. */
    private function sourceVideo(int $seconds): Media
    {
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'width' => 640, 'height' => 360]);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.mp4"])->save();
        $target = Storage::disk('media')->path($media->path);
        File::ensureDirectoryExists(dirname($target));
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", '-f', 'lavfi', '-i', "sine=f=440:d={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(180)->mustRun();

        return $media;
    }

    private function project(): VideoProject
    {
        return VideoProject::factory()->for($this->user)->clipping()->create([
            'source_media_id' => $this->source->id,
            'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'title' => 'Conférence IA 2026',
        ]);
    }

    /** Réponses OpenAI simulées : transcription horodatée, puis passages proposés (dont un trop long). */
    private function fakeOpenAi(): void
    {
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response([
                'text' => 'Introduction. Idée forte. Démonstration. Conclusion.',
                'language' => 'french',
                'segments' => [
                    ['start' => 0.0, 'end' => 5.0, 'text' => 'Introduction.'],
                    ['start' => 5.0, 'end' => 35.0, 'text' => 'Idée forte.'],
                    ['start' => 35.0, 'end' => 80.0, 'text' => 'Démonstration.'],
                    ['start' => 80.0, 'end' => 90.0, 'text' => 'Conclusion.'],
                ],
            ]),
            'api.openai.com/v1/responses' => Http::response([
                'object' => 'response', 'status' => 'completed', 'model' => 'analyse-test', 'id' => 'resp_test',
                'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['clips' => [
                    ['start_ms' => 5_000, 'end_ms' => 35_000, 'title' => 'L\'idée forte', 'hook' => 'L\'idée forte', 'topic' => 'Le cœur du sujet', 'reason' => 'Accroche immédiate', 'confidence' => 90,
                        'criteria' => ['hook' => 95, 'value' => 90, 'autonomy' => 90, 'emotion' => 80, 'rhythm' => 85, 'quality' => 90]],
                    ['start_ms' => 20_000, 'end_ms' => 80_000, 'title' => 'La démonstration', 'hook' => 'La démo', 'topic' => 'Démo', 'reason' => 'Visuel', 'confidence' => 80,
                        'criteria' => ['hook' => 70, 'value' => 80, 'autonomy' => 70, 'emotion' => 60, 'rhythm' => 70, 'quality' => 80]],
                    ['start_ms' => 100_000, 'end_ms' => 130_000, 'title' => 'Hors vidéo', 'hook' => 'h', 'reason' => 'r', 'confidence' => 99,
                        'criteria' => ['hook' => 99, 'value' => 99, 'autonomy' => 99, 'emotion' => 99, 'rhythm' => 99, 'quality' => 99]],
                ]])]]]],
                'usage' => ['input_tokens' => 120, 'output_tokens' => 80],
            ]),
        ]);
    }

    private function analyze(VideoProject $project): VideoProject
    {
        app()->call([new AnalyzeClipSourceJob($project->id), 'handle']);

        return $project->fresh();
    }

    private function render(VideoClip $clip): VideoClip
    {
        app()->call([new RenderClipJob($clip->id), 'handle']);

        return $clip->fresh();
    }

    /** Durée réelle (ms) relue par FFprobe sur le fichier publié dans la médiathèque. */
    private function realDuration(Media $media): int
    {
        return (int) app(MediaProbe::class)->probe(Storage::disk('media')->path($media->path))->durationMs;
    }

    public function test_analysis_suggests_passages_and_real_clips_of_30_and_60_seconds_are_published(): void
    {
        $this->fakeOpenAi();
        $project = $this->analyze($this->project());

        $this->assertSame('ready', $project->status->value, (string) $project->error);
        $this->assertEqualsWithDelta(self::SOURCE_SECONDS * 1000, $project->duration_ms, 150, 'Durée mesurée par FFprobe.');
        $this->assertDatabaseHas('video_transcripts', ['video_project_id' => $project->id, 'status' => 'ready']);
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'task' => 'transcription', 'status' => 'succeeded', 'model' => 'transcription-test']);
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'task' => 'video_analysis', 'status' => 'succeeded', 'input_tokens' => 120]);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/audio/transcriptions') && $request->isMultipart());

        $suggestions = VideoClip::query()->where('video_project_id', $project->id)->orderByDesc('score')->get();
        $this->assertCount(2, $suggestions, 'Passage hors de la vidéo écarté.');
        [$thirty, $long] = [$suggestions[0], $suggestions[1]];
        $this->assertSame([5_000, 35_000], [$thirty->start_ms, $thirty->end_ms]);
        $this->assertSame([20_000, 80_000], [$long->start_ms, $long->end_ms], 'Passage de 60 s exactement proposé par l\'IA.');
        $this->assertSame('Le cœur du sujet', $thirty->topic);

        // Clip de 30 s (proposé) : rendu réel, publié dans la médiathèque.
        $this->api()->postJson("/api/v1/clipper/clips/{$thirty->id}/render")->assertStatus(202);
        Queue::assertPushed(RenderClipJob::class, fn ($job) => $job->clipId === $thirty->id);
        $thirty = $this->render($thirty);
        $this->assertSame(ClipStatus::Rendered, $thirty->status, (string) $thirty->error_message);
        $media = Media::findOrFail($thirty->output_media_id);
        $this->assertSame('video', $media->type->value);
        $this->assertSame('video/mp4', $media->mime_type);
        $this->assertSame([1080, 1920], [$media->width, $media->height], 'Format court 9:16.');
        $this->assertSame('youtube_clipper', $media->metadata['source']);
        $this->assertSame($thirty->id, $media->metadata['video_clip_id']);
        $this->assertEqualsWithDelta(30_000, $this->realDuration($media), 250, 'Durée réelle relue par FFprobe.');

        // Clip de 60 s (proposé, borné) : exactement à la limite, accepté.
        $this->api()->postJson("/api/v1/clipper/clips/{$long->id}/render", ['aspect_ratio' => '1:1'])->assertStatus(202);
        $long = $this->render($long);
        $this->assertSame(ClipStatus::Rendered, $long->status, (string) $long->error_message);
        $published = Media::findOrFail($long->output_media_id);
        $this->assertSame([1080, 1080], [$published->width, $published->height]);
        $this->assertLessThanOrEqual(ClipLimits::MAX_CLIP_MS, $this->realDuration($published), 'Jamais plus de 60 s.');
        $this->assertGreaterThanOrEqual(59_750, $this->realDuration($published));

        // Le clip publié est un média normal : URL signée lisible.
        $this->api()->getJson('/api/v1/clipper/projects/'.$project->id)->assertOk()
            ->assertJsonPath('data.clips.0.status', 'rendered')
            ->assertJsonPath('data.clips.0.output.type', 'video');
        $this->assertSame([], File::directories($this->temp), 'Aucun dossier de travail restant.');
    }

    public function test_a_manual_clip_of_thirty_seconds_is_rendered_even_without_ai(): void
    {
        // Aucune IA, aucune transcription : l'analyse se termine proprement, sans proposition
        // (son continu, aucune pause), et le passage manuel reste possible.
        config(['ai.tasks.transcription.model' => null, 'ai.tasks.video_analysis.model' => null, 'video.clipper.transcription' => 'auto']);
        config(['video.clipper.whisper.binary' => storage_path('framework/testing/whisper-absent.exe')]);
        $project = $this->analyze($this->project());

        $this->assertSame('ready', $project->status->value, (string) $project->error);
        $this->assertSame('none', $project->settings['analysis']['mode']);
        $this->assertNull($project->settings['analysis']['transcription']);
        $this->assertNotNull($project->duration_ms, 'Durée connue : passage manuel possible.');
        $this->assertDatabaseCount('video_clips', 0);
        Http::assertNothingSent();
        $this->api()->getJson("/api/v1/clipper/projects/{$project->id}")->assertOk()
            ->assertJsonPath('data.analysis.mode', 'none')->assertJsonPath('meta.analysis_mode', 'local')->assertJsonPath('meta.transcription_mode', 'none');

        $id = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 40_000, 'end_ms' => 70_000])->assertStatus(202)->json('data.id');
        $clip = $this->render(VideoClip::findOrFail($id));
        $this->assertSame(ClipStatus::Rendered, $clip->status, (string) $clip->error_message);
        $this->assertEqualsWithDelta(30_000, $this->realDuration(Media::findOrFail($clip->output_media_id)), 250);
    }

    public function test_provider_errors_never_block_the_project(): void
    {
        // Quota épuisé : la transcription échoue, l'IA n'a rien à analyser, l'analyse locale prend
        // le relais ; le projet reste utilisable (passage manuel), aucun secret n'est exposé.
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['code' => 'credit_balance_exhausted', 'message' => 'x']], 429)]);
        $project = $this->analyze($this->project());

        $this->assertSame('ready', $project->status->value);
        $this->assertNotNull($project->duration_ms);
        $this->assertSame('NO_TRANSCRIPT', $project->settings['analysis']['fallback']);
        $this->assertStringContainsString('Crédits ou quota', implode(' ', $project->settings['analysis']['notes']));
        $this->assertStringNotContainsString('sk-test', (string) json_encode($project->settings));
        $this->assertDatabaseHas('ai_generations', ['video_project_id' => $project->id, 'task' => 'transcription', 'status' => 'failed']);

        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 20_000])->assertStatus(202);
    }

    public function test_processing_errors_fail_the_clip_cleanly_without_publishing(): void
    {
        $project = $this->project();
        // Durée enregistrée plus longue que la vraie vidéo : l'extrait demandé n'existe pas.
        $project->forceFill(['status' => 'ready', 'duration_ms' => 600_000])->save();
        $id = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 100_000, 'end_ms' => 130_000])->assertStatus(202)->json('data.id');
        $clip = $this->render(VideoClip::findOrFail($id));
        $this->assertSame(ClipStatus::Failed, $clip->status);
        $this->assertSame('SOURCE_RANGE', $clip->error_code);

        // Fichier source supprimé entre-temps.
        Storage::disk('media')->delete($this->source->path);
        $id = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 30_000])->assertStatus(202)->json('data.id');
        $clip = $this->render(VideoClip::findOrFail($id));
        $this->assertSame(ClipStatus::Failed, $clip->status);
        $this->assertSame('SOURCE_MISSING', $clip->error_code);

        $this->assertSame(1, Media::query()->count(), 'Seule la source : aucun clip publié.');
        $this->assertSame([], File::directories($this->temp));
    }

    public function test_a_file_measured_above_sixty_seconds_by_ffprobe_is_never_published(): void
    {
        $project = $this->project();
        $project->forceFill(['status' => 'ready', 'duration_ms' => self::SOURCE_SECONDS * 1000])->save();
        // Moteur dont FFprobe mesure 61 s sur le fichier produit (sortie anormale simulée).
        $this->app->bind(FfmpegVideoRenderer::class, fn ($app) => new class($app->make(FfmpegBinary::class), $app->make(MediaProbe::class), $app->make(FfmpegCommandBuilder::class)) extends FfmpegVideoRenderer
        {
            public function produce(array $spec, array $sources, array $hasAudio, RenderWorkspace $workspace, Closure $onProgress, Closure $shouldCancel): RenderedVideo
            {
                $video = parent::produce($spec, $sources, $hasAudio, $workspace, $onProgress, $shouldCancel);
                $probe = $video->probe;

                return new RenderedVideo($video->path, $video->thumbnailPath, new ProbeResult($probe->formatName, 61_000, true, $probe->hasAudio, $probe->videoCodec, $probe->audioCodec, $probe->width, $probe->height, $probe->fps), $video->sizeBytes);
            }
        });

        $id = $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/clips", ['start_ms' => 0, 'end_ms' => 30_000])->assertStatus(202)->json('data.id');
        $clip = $this->render(VideoClip::findOrFail($id));

        $this->assertSame(ClipStatus::Failed, $clip->status);
        $this->assertSame('OUTPUT_TOO_LONG', $clip->error_code);
        $this->assertNull($clip->output_media_id);
        $this->assertSame(1, Media::query()->count(), 'Aucun média publié.');
    }
}
