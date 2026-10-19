<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\Planning\ClipPlanner;
use App\Domain\Video\Clipper\Rendering\RemotionRenderer;
use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\ClipStatus;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\MediaProbe;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Http\Resources\MediaResource;
use App\Jobs\RenderClipJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoClip;
use App\Models\VideoProject;
use App\Models\VideoTranscript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Pipeline de rendu RÉEL du Clipper avec composition Remotion (ADR-052) : source autorisée (vidéo de
 * test produite par FFmpeg) → FFmpeg (découpe, recadrage) → Remotion (accroche, sous-titres) → FFprobe
 * → médiathèque privée. Les scénarios d'échec du moteur utilisent un faux lanceur Node (simulé) ;
 * tout le reste est réel. Ignoré si FFmpeg, Remotion ou Chrome manquent.
 */
#[Group('remotion')]
class ClipperRemotionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $chrome = config('video.remotion.browser_executable') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        $binaries = app(FfmpegBinary::class);
        if (! $binaries->isAvailable() || ! $binaries->isProbeAvailable() || ! app(RemotionRenderer::class)->isAvailable() || ! is_file($chrome)) {
            $this->markTestSkipped('FFmpeg/FFprobe, Remotion (renderer/node_modules) ou Chrome introuvable : rendu réel ignoré.');
        }
        Queue::fake();
        Storage::fake('media');
        Http::preventStrayRequests();
        $this->temp = storage_path('framework/testing/clipper-remotion');
        config([
            'video.temp_path' => $this->temp,
            'video.clipper.composer' => 'remotion',
            'video.clipper.show_hook' => true,
            'video.remotion.browser_executable' => $chrome,
        ]);
        $this->user = User::factory()->create();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        File::deleteDirectory(storage_path('framework/testing/fake-renderer'));
        parent::tearDown();
    }

    private function api(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->user)->fromSpa();
    }

    /** Source AUTORISÉE : vidéo de test 640×360 avec son, produite par FFmpeg (aucun téléchargement). */
    private function project(int $seconds = 12): VideoProject
    {
        $media = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4', 'width' => 640, 'height' => 360]);
        $media->forceFill(['path' => "users/{$this->user->id}/media/{$media->id}/original.mp4"])->save();
        $target = Storage::disk('media')->path($media->path);
        File::ensureDirectoryExists(dirname($target));
        (new Process([config('video.binaries.ffmpeg'), '-hide_banner', '-loglevel', 'error', '-y',
            '-f', 'lavfi', '-i', "testsrc2=s=640x360:r=25:d={$seconds}", '-f', 'lavfi', '-i', "sine=f=440:d={$seconds}",
            '-c:v', 'libx264', '-preset', 'ultrafast', '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-shortest', $target]))->setTimeout(300)->mustRun();

        $project = VideoProject::factory()->for($this->user)->clipping()->create(['source_media_id' => $media->id, 'source_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'title' => 'Conférence']);
        $project->forceFill(['status' => 'ready', 'duration_ms' => $seconds * 1000])->save();

        return $project;
    }

    /** Transcription française (accents) avec mots horodatés. */
    private function transcript(VideoProject $project): void
    {
        VideoTranscript::query()->create(['video_project_id' => $project->id, 'language' => 'fr', 'provider' => 'whisper.cpp', 'status' => 'ready', 'full_text' => '…', 'segments' => [
            ['start_ms' => 1_000, 'end_ms' => 4_000, 'text' => 'Voici l\'idée phare de l\'été.', 'words' => [[1_000, 1_400, 'Voici'], [1_400, 2_000, 'l\'idée'], [2_000, 2_600, 'phare'], [2_600, 3_000, 'de'], [3_000, 4_000, 'l\'été.']]],
            ['start_ms' => 4_000, 'end_ms' => 6_000, 'text' => 'Réseaux sociaux : ça marche !'],
        ]]);
    }

    private function suggestion(VideoProject $project, int $start, int $end, ?string $hook): VideoClip
    {
        $clip = new VideoClip(['title' => 'Passage', 'reason' => 'Accroche', 'start_ms' => $start, 'end_ms' => $end, 'aspect_ratio' => '9:16']);
        $clip->video_project_id = $project->id;
        $clip->forceFill(['source' => 'ai', 'status' => 'suggested', 'score' => 88.5, 'hook' => $hook, 'confidence' => 90, 'score_breakdown' => ['hook' => 90, 'value' => 90, 'autonomy' => 85, 'emotion' => 80, 'rhythm' => 90, 'quality' => 90]])->save();

        return $clip;
    }

    private function render(VideoClip $clip): VideoClip
    {
        app()->call([new RenderClipJob($clip->id), 'handle']);

        return $clip->fresh();
    }

    public function test_a_vertical_clip_is_composed_with_hook_and_french_subtitles_then_published_privately(): void
    {
        $project = $this->project();
        $this->transcript($project);
        $before = Media::query()->count();
        $clip = $this->suggestion($project, 1_000, 6_000, 'L\'été arrive : prêts ?');

        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render", ['aspect_ratio' => '9:16', 'subtitles' => true])->assertStatus(202);
        $this->api(User::factory()->create())->postJson("/api/v1/clipper/clips/{$clip->id}/render")->assertNotFound();
        $clip = $this->render($clip);

        $this->assertSame(ClipStatus::Rendered, $clip->status, (string) $clip->error_message);
        $media = Media::findOrFail($clip->output_media_id);
        $this->assertSame($before + 1, Media::query()->count());
        $this->assertSame([$this->user->id, 'video/mp4', 'ready'], [$media->user_id, $media->mime_type, $media->status->value]);
        Storage::disk('media')->assertExists($media->path);
        $probe = app(MediaProbe::class)->probe(Storage::disk('media')->path($media->path));
        $this->assertSame([1080, 1920], [$probe->width, $probe->height]);
        $this->assertSame(['h264', 'aac'], [$probe->videoCodec, $probe->audioCodec]);
        $this->assertTrue($probe->hasAudio, 'Son du passage conservé par la composition.');
        $this->assertEqualsWithDelta(5_000, $probe->durationMs, 120);
        $this->assertEquals(['composer' => 'remotion', 'template' => 'clean-short', 'hook' => true, 'captions' => 2, 'crop' => 'center'], $media->metadata['composition']);
        $this->assertNotNull($media->derivatives['thumbnail'] ?? null, 'Vignette enregistrée.');

        // Stockage privé : URL signée lisible, refusée sans signature.
        $url = (new MediaResource($media))->resolve()['url'];
        $this->get($url)->assertOk();
        $this->get(strtok($url, '?'))->assertForbidden();
        $this->assertSame([], File::directories($this->temp), 'Dossier de travail supprimé après publication.');
    }

    public function test_square_and_landscape_without_hook_nor_transcript_have_an_explicit_plain_composition(): void
    {
        $project = $this->project(8);
        foreach ([['1:1', 1080, 1080], ['16:9', 1920, 1080]] as [$ratio, $width, $height]) {
            $clip = $this->suggestion($project, 2_000, 5_000, null);
            $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render", ['aspect_ratio' => $ratio])->assertStatus(202);
            $clip = $this->render($clip);

            $this->assertSame(ClipStatus::Rendered, $clip->status, "{$ratio} : ".$clip->error_message);
            $media = Media::findOrFail($clip->output_media_id);
            $this->assertSame([$width, $height], [$media->width, $media->height], $ratio);
            $this->assertEqualsWithDelta(3_000, $media->duration_ms, 120);
            $this->assertEquals(['composer' => 'remotion', 'template' => 'clean-short', 'hook' => false, 'captions' => 0, 'crop' => 'center'], $media->metadata['composition'], 'Sans accroche ni transcription : vidéo seule, comportement explicite.');
        }
    }

    public function test_relative_caption_timestamps_match_remotion_frames(): void
    {
        $project = $this->project();
        $this->transcript($project);
        $plan = app(ClipPlanner::class)->plan(['start_ms' => 2_000, 'end_ms' => 5_000, 'hook' => null], AspectRatio::Vertical, [
            ['start_ms' => 1_000, 'end_ms' => 4_000, 'text' => 'Voici l\'idée phare de l\'été.'],
            ['start_ms' => 4_000, 'end_ms' => 6_000, 'text' => 'Réseaux sociaux : ça marche !'],
        ]);

        // Légendes relatives au début du clip (2 s) et bornées à sa fin : 0→2000 puis 2000→3000 ms,
        // soit les images 0→60 puis 60→90 à 30 i/s.
        $this->assertSame([[0, 2_000, 'Voici l\'idée phare de l\'été.'], [2_000, 3_000, 'Réseaux sociaux : ça marche !']], array_map(fn ($c) => [$c['start_ms'], $c['end_ms'], $c['text']], $plan->captions));
        $this->assertSame(90, $plan->durationInFrames());
    }

    public function test_engine_failures_never_publish_and_a_retry_succeeds(): void
    {
        config(['video.clipper.remotion_fallback' => false]); // repli désactivé : l'échec est visible
        $project = $this->project(8);
        $existing = Media::findOrFail($project->source_media_id);
        foreach (['empty' => 'REMOTION_RENDER_FAILED', 'invalid' => 'OUTPUT_INVALID', 'crash' => 'REMOTION_RENDER_FAILED'] as $mode => $code) {
            $this->fakeRenderer($mode);
            $clip = $this->suggestion($project, 1_000, 4_000, 'Accroche');
            $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render")->assertStatus(202);
            $clip = $this->render($clip);

            $this->assertSame(ClipStatus::Failed, $clip->status, $mode);
            $this->assertSame($code, $clip->error_code, $mode);
            $this->assertNull($clip->output_media_id);
            $this->assertStringNotContainsString('render.mjs', (string) $clip->error_message, 'Jamais de détail interne affiché.');
        }
        $this->assertSame(1, Media::query()->count(), 'Aucun média publié, la source est intacte.');
        $this->assertNotSoftDeleted('media', ['id' => $existing->id]);
        $this->assertSame([], File::directories($this->temp), 'Dossiers de travail supprimés après échec.');

        // Nouvel essai du même clip, moteur réparé : réussi.
        config(['video.remotion.path' => base_path('../renderer')]);
        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render")->assertStatus(202);
        $this->assertSame(ClipStatus::Rendered, $this->render($clip)->status);
    }

    public function test_a_remotion_failure_falls_back_to_a_real_ffmpeg_render_when_enabled(): void
    {
        config(['video.clipper.remotion_fallback' => true]);
        $project = $this->project(8);
        $this->transcript($project);
        $this->fakeRenderer('crash'); // Remotion en échec (lanceur simulé) ; FFmpeg et FFprobe réels
        $clip = $this->suggestion($project, 1_000, 6_000, 'Accroche');
        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render", ['aspect_ratio' => '9:16', 'subtitles' => true])->assertStatus(202);
        $clip = $this->render($clip);

        $this->assertSame(ClipStatus::Rendered, $clip->status, (string) $clip->error_message);
        $media = Media::findOrFail($clip->output_media_id);
        $this->assertEquals(['composer' => 'ffmpeg', 'fallback_from' => 'remotion', 'fallback_reason' => 'REMOTION_RENDER_FAILED'], $media->metadata['composition']);
        $probe = app(MediaProbe::class)->probe(Storage::disk('media')->path($media->path));
        $this->assertSame([1080, 1920, 'h264', 'aac'], [$probe->width, $probe->height, $probe->videoCodec, $probe->audioCodec]);
        $this->assertEqualsWithDelta(5_000, $probe->durationMs, 150);
        $this->assertSame([], File::directories($this->temp), 'Dossier de travail supprimé.');
    }

    public function test_remotion_receives_a_bounded_step_timeout(): void
    {
        // Délai d'étape de Remotion (30 s par défaut chez Remotion, dépassé sur une machine chargée, ADR-057).
        config(['video.clipper.remotion_fallback' => false]);
        $project = $this->project(6);
        foreach ([[120, 120], [1, 10], [9_999, 600]] as [$configured, $expected]) {
            config(['video.remotion.step_timeout' => $configured]);
            $this->fakeRenderer('env');
            $clip = $this->suggestion($project, 1_000, 4_500, 'Accroche');
            $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render")->assertStatus(202);
            $this->assertSame(ClipStatus::Failed, $this->render($clip)->status);

            $received = json_decode((string) File::get(storage_path('framework/testing/fake-renderer/env.json')), true);
            $this->assertSame((string) ($expected * 1000), $received['step'], "{$configured} s configurées");
        }
    }

    public function test_remotion_progress_lines_are_reported_as_real_percentages(): void
    {
        $this->fakeRenderer('progress');
        $workspace = RenderWorkspace::create((string) Str::uuid());
        try {
            $video = $workspace->path('work', 'passage.mp4');
            File::put($video, 'x');
            $plan = app(ClipPlanner::class)->plan(['start_ms' => 0, 'end_ms' => 4_000, 'hook' => null], AspectRatio::from('9:16'), [], subtitles: false, showHook: false);
            $seen = [];
            try {
                app(RemotionRenderer::class)->render($plan, $video, $workspace, onProgress: function (int $percent) use (&$seen) {
                    $seen[] = $percent;
                });
                $this->fail('Le faux rendu échoue après sa progression.');
            } catch (RenderFailure $e) {
                $this->assertSame('REMOTION_RENDER_FAILED', $e->errorCode);
            }
            $this->assertSame([10, 55, 100], $seen, 'Avancement transmis tel que mesuré par Remotion, y compris les dernières lignes.');
        } finally {
            $workspace->delete();
        }
    }

    public function test_cancellation_before_and_during_rendering_publishes_nothing(): void
    {
        $project = $this->project(8);
        $clip = $this->suggestion($project, 1_000, 4_000, 'Accroche');
        $this->api()->postJson("/api/v1/clipper/clips/{$clip->id}/render")->assertStatus(202);

        // Annulé avant le démarrage du worker : le job retardataire ne fait rien.
        $this->api()->postJson("/api/v1/clipper/projects/{$project->id}/cancel")->assertOk();
        $clip = $this->render($clip);
        $this->assertSame([ClipStatus::Failed, 'CANCELLED', null], [$clip->status, $clip->error_code, $clip->output_media_id]);
        $this->assertSame(1, Media::query()->count());

        // Annulé PENDANT la composition : le processus Node est réellement arrêté, rien n'est produit.
        $this->fakeRenderer('slow');
        $workspace = RenderWorkspace::create('00000000-0000-0000-0000-0000000c0052');
        $plan = app(ClipPlanner::class)->plan(['start_ms' => 0, 'end_ms' => 3_000], AspectRatio::Vertical);
        $checks = 0;
        $started = microtime(true);
        try {
            app(RemotionRenderer::class)->render($plan, null, $workspace, function () use (&$checks) {
                return ++$checks > 4;
            });
            $this->fail('Annulation attendue.');
        } catch (RenderFailure $e) {
            $this->assertSame('CANCELLED', $e->errorCode);
        } finally {
            $workspace->delete();
        }
        $this->assertLessThan(15, microtime(true) - $started, 'Processus arrêté sans attendre sa fin (30 s).');
        $this->assertDirectoryDoesNotExist($workspace->root);
    }

    /**
     * Faux lanceur Node (SIMULATION, scénarios d'échec uniquement) : « empty » écrit un fichier vide,
     * « invalid » un fichier non vidéo, « crash » échoue, « slow » attend 30 s.
     */
    private function fakeRenderer(string $mode): void
    {
        $dir = storage_path('framework/testing/fake-renderer');
        File::ensureDirectoryExists($dir.'/node_modules/remotion');
        File::ensureDirectoryExists($dir.'/node_modules/@remotion/renderer');
        $script = <<<'JS'
            const out = process.argv[process.argv.indexOf("--out") + 1]
            const fs = await import("node:fs")
            const path = await import("node:path")
            fs.mkdirSync(path.dirname(out), { recursive: true })
            const mode = process.env.FAKE_MODE
            if (mode === "env") { fs.writeFileSync(path.join(process.cwd(), "env.json"), JSON.stringify({ step: process.env.REMOTION_STEP_TIMEOUT_MS ?? null })) }
            if (mode === "crash" || mode === "env") { process.stdout.write('{"ok":false,"code":"REMOTION_RENDER_FAILED"}\n'); process.exit(1) }
            if (mode === "slow") { await new Promise((r) => setTimeout(r, 30000)) }
            if (mode === "progress") { for (const p of [0.1, 0.55, 1]) { process.stdout.write(JSON.stringify({ progress: p, rendered: 1, encoded: 1 }) + "\n") } process.stdout.write('{"ok":false,"code":"REMOTION_RENDER_FAILED"}\n'); process.exit(1) }
            fs.writeFileSync(out, mode === "invalid" ? "pas une vidéo" : "")
            process.stdout.write('{"ok":true}\n')
            JS;
        File::put($dir.'/render.mjs', str_replace('process.env.FAKE_MODE', json_encode($mode), $script));
        config(['video.remotion.path' => $dir]);
    }
}
