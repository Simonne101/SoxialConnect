<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Rendering\ExecutableCheck;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\ProbeResult;
use App\Domain\Video\Rendering\RenderedVideo;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Domain\Video\VideoRenderService;
use App\Jobs\RenderVideoJob;
use App\Models\Media;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoProjectAsset;
use App\Models\VideoRenderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pipeline de rendu sans exécuter FFmpeg (ADR-045) : spécification déterministe, réutilisation d'un
 * rendu identique, statuts, publication refusée après annulation, propriété revérifiée par le moteur.
 */
class RenderPipelineTest extends TestCase
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
        $this->project = $this->api()->postJson('/api/v1/video/projects', ['title' => 'Pipeline'])->json('data');
        $this->app->instance(FfmpegBinary::class, new class extends FfmpegBinary
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function isProbeAvailable(): bool
            {
                return true;
            }
        });
    }

    private function api(): static
    {
        return $this->actingAs($this->user)->fromSpa();
    }

    private function addText(string $text, int $startMs = 0, int $durationMs = 2000): string
    {
        $track = collect($this->project['tracks'])->firstWhere('type', 'text')['id'];

        return $this->api()->postJson("/api/v1/video/tracks/{$track}/items", ['type' => 'text', 'start_ms' => $startMs, 'duration_ms' => $durationMs, 'properties' => ['text' => $text]])->assertCreated()->json('data.id');
    }

    private function requestRender(): VideoRenderJob
    {
        $id = $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertSuccessful()->json('data.id');

        return VideoRenderJob::findOrFail($id);
    }

    public function test_the_render_specification_is_deterministic_and_follows_real_timeline_changes(): void
    {
        $this->addText('B', 3000);
        $item = $this->addText('A');
        $first = $this->requestRender();

        // Même timeline : même empreinte (nouveau rendu après annulation).
        $this->api()->postJson("/api/v1/video/renders/{$first->id}/cancel")->assertOk();
        $second = $this->requestRender();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame($first->spec_hash, $second->spec_hash);
        $this->assertSame(['A', 'B'], array_column(array_column($second->spec['tracks'][1]['items'], 'properties'), 'text'), 'Éléments dans l\'ordre du temps, pas de création.');

        // Changement réel : empreinte différente.
        $this->api()->postJson("/api/v1/video/renders/{$second->id}/cancel")->assertOk();
        $this->api()->patchJson("/api/v1/video/timeline-items/{$item}", ['duration_ms' => 2500])->assertOk();
        $this->assertNotSame($first->spec_hash, $this->requestRender()->spec_hash);

        // Ordre des clés sans effet (MySQL réordonne les objets JSON).
        $spec = ['b' => 1, 'a' => ['y' => [3, 1], 'x' => null]];
        $this->assertSame(VideoRenderService::hashSpec($spec), VideoRenderService::hashSpec(['a' => ['x' => null, 'y' => [3, 1]], 'b' => 1]));
        $this->assertNotSame(VideoRenderService::hashSpec($spec), VideoRenderService::hashSpec(['b' => 1, 'a' => ['y' => [1, 3], 'x' => null]]), 'L\'ordre des listes compte.');
    }

    public function test_an_identical_successful_render_is_reused_instead_of_recomputed(): void
    {
        $this->addText('Bonjour');
        $render = $this->requestRender();
        $output = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $render->forceFill(['status' => 'succeeded', 'progress' => 100, 'output_media_id' => $output->id, 'finished_at' => now()])->save();
        VideoProject::query()->whereKey($this->project['id'])->update(['status' => 'ready']);
        Queue::fake();

        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertOk()
            ->assertJsonPath('data.id', $render->id)->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.output.id', $output->id)->assertJsonPath('message', 'Cette version de la vidéo existe déjà.');
        Queue::assertNothingPushed();
        $this->assertSame(1, VideoRenderJob::count());

        // Vidéo supprimée de la médiathèque : un nouveau rendu est planifié.
        $output->delete();
        $this->api()->postJson("/api/v1/video/projects/{$this->project['id']}/renders")->assertStatus(202);
        Queue::assertPushed(RenderVideoJob::class, 1);
    }

    public function test_status_transitions_are_guarded(): void
    {
        $this->addText('Bonjour');
        $render = $this->requestRender();
        $service = app(VideoRenderService::class);

        $this->assertSame('rendering', $service->start($render->id)->status->value);
        $this->assertNull($service->start($render->id), 'Un job dupliqué ne reprend pas un rendu déjà en cours.');

        $service->progress($render, 40);
        $service->progress($render, 20); // jamais en arrière
        $service->progress($render, 150); // jamais 100 avant la validation
        $this->assertSame(99, $render->fresh()->progress);

        $this->assertFalse($service->isCancelled($render));
        $service->cancel($render->fresh());
        $this->assertTrue($service->isCancelled($render));
        $this->assertSame('draft', VideoProject::findOrFail($this->project['id'])->status->value);

        $this->api()->postJson("/api/v1/video/renders/{$render->id}/cancel")->assertStatus(409)->assertJsonPath('code', 'NOT_CANCELLABLE');
        $service->fail($render, 'X', 'x');
        $this->assertSame('cancelled', $render->fresh()->status->value, 'Un rendu terminé ne change plus de statut.');
    }

    public function test_nothing_is_published_when_the_render_was_cancelled_during_encoding(): void
    {
        $this->addText('Bonjour');
        $render = $this->requestRender();
        $service = app(VideoRenderService::class);
        $service->start($render->id);
        $service->cancel($render->fresh());

        $file = tempnam(sys_get_temp_dir(), 'sxc-test-');
        file_put_contents($file, str_repeat('x', 1024));
        $probe = new ProbeResult('mov,mp4,m4a,3gp,3g2,mj2', 2000, true, false, 'h264', null, 1080, 1920, 30.0);

        $this->assertFalse($service->complete($render->fresh(), new RenderedVideo($file, null, $probe, 1024)));
        $this->assertSame(0, Media::count());
        $this->assertSame([], Storage::disk('media')->allFiles(), 'Aucun fichier orphelin.');
        $this->assertSame('cancelled', $render->fresh()->status->value);
        @unlink($file);
    }

    public function test_the_engine_re_checks_ownership_of_every_source(): void
    {
        $this->addText('Bonjour');
        $render = $this->requestRender();
        $foreign = Media::factory()->for(User::factory())->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);
        $mine = Media::factory()->for($this->user)->create(['type' => 'video', 'mime_type' => 'video/mp4', 'extension' => 'mp4']);

        foreach ([$foreign->id => 'média d\'un autre utilisateur', $mine->id => 'média non rattaché au projet'] as $mediaId => $case) {
            // Spécification falsifiée : seule la base fait foi, jamais un identifiant reçu.
            $spec = $render->spec;
            $spec['tracks'][0]['items'][] = ['type' => 'video', 'media_id' => $mediaId, 'start_ms' => 0, 'duration_ms' => 1000, 'source_start_ms' => null, 'source_duration_ms' => null, 'properties' => [], 'transitions' => null];
            $render->forceFill(['spec' => $spec])->save();
            $workspace = RenderWorkspace::create($render->id);
            try {
                app(FfmpegVideoRenderer::class)->render($render->fresh(), $workspace, fn () => null, fn () => false);
                $this->fail("Source refusée attendue : {$case}.");
            } catch (RenderFailure $e) {
                $this->assertSame('SOURCE_MISSING', $e->errorCode, $case);
            } finally {
                $workspace->delete();
            }
        }
        $this->assertFalse(VideoProjectAsset::query()->where('media_id', $foreign->id)->exists());
    }

    public function test_missing_binaries_are_detected(): void
    {
        $this->app->forgetInstance(FfmpegBinary::class);
        config(['video.binaries.ffmpeg' => 'ffmpeg-introuvable-'.uniqid(), 'video.binaries.ffprobe' => 'ffprobe-introuvable-'.uniqid()]);
        Cache::flush();
        $binaries = new FfmpegBinary;

        $this->assertFalse($binaries->isAvailable());
        $this->assertFalse($binaries->isProbeAvailable());
        $this->assertFalse(app(VideoRenderService::class)->rendererAvailable());
    }

    public function test_a_missing_binary_is_never_shared_with_other_processes(): void
    {
        $binary = 'ffmpeg-introuvable-'.uniqid();
        config(['video.binaries.ffmpeg' => $binary]);
        Cache::flush();

        $this->assertFalse((new FfmpegBinary)->isAvailable());
        // ADR-060 : un échec n'est mémorisé que dans le processus qui l'a constaté (30 s), jamais dans le
        // cache partagé : un autre processus (worker, installation récente) revérifie aussitôt.
        $this->assertFalse(Cache::has('video:executable:ffmpeg:'.hash('sha256', $binary.'|path')));
        app(ExecutableCheck::class)->forget('ffmpeg', $binary); // équivalent d'un nouveau processus
        $this->assertFalse((new FfmpegBinary)->isAvailable(), 'Toujours absent : revérifié, pas lu dans un cache.');
    }
}
