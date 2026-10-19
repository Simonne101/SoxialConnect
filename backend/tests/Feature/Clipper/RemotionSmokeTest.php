<?php

namespace Tests\Feature\Clipper;

use App\Domain\Video\Clipper\Planning\ClipPlanner;
use App\Domain\Video\Clipper\Rendering\RemotionRenderer;
use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Test de fumée RÉEL de la composition Remotion (ADR-051) : RenderPlan → paquet `renderer/` (Node,
 * Chrome) → MP4 → contrôle FFprobe. Ignoré si Remotion, FFmpeg ou Chrome ne sont pas installés.
 */
#[Group('remotion')]
class RemotionSmokeTest extends TestCase
{
    private string $temp;

    protected function setUp(): void
    {
        parent::setUp();
        $chrome = config('video.remotion.browser_executable') ?: 'C:/Program Files/Google/Chrome/Application/chrome.exe';
        $binaries = app(FfmpegBinary::class);
        if (! app(RemotionRenderer::class)->isAvailable() || ! $binaries->isAvailable() || ! $binaries->isProbeAvailable() || ! is_file($chrome)) {
            $this->markTestSkipped('Remotion (renderer/node_modules), FFmpeg/FFprobe ou Chrome introuvable : rendu réel ignoré.');
        }
        $this->temp = storage_path('framework/testing/remotion');
        config(['video.temp_path' => $this->temp, 'video.remotion.browser_executable' => $chrome]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temp ?? '');
        parent::tearDown();
    }

    public function test_a_render_plan_becomes_a_real_vertical_mp4_validated_by_ffprobe(): void
    {
        $plan = app(ClipPlanner::class)->plan(
            ['start_ms' => 5_000, 'end_ms' => 8_000, 'hook' => 'Tu fais probablement cette erreur'],
            AspectRatio::Vertical,
            [['start_ms' => 5_000, 'end_ms' => 8_000, 'text' => 'Première légende du clip.']],
        );
        $workspace = RenderWorkspace::create('00000000-0000-0000-0000-00000000a051');

        try {
            $video = app(RemotionRenderer::class)->render($plan, null, $workspace);

            $this->assertTrue($video->probe->hasVideo);
            $this->assertSame([1080, 1920], [$video->probe->width, $video->probe->height]);
            $this->assertSame('h264', $video->probe->videoCodec);
            $this->assertEqualsWithDelta(3_000, $video->probe->durationMs, 100);
            $this->assertGreaterThan(0, $video->sizeBytes);
            $this->assertNotNull($video->thumbnailPath, 'Vignette produite pour la médiathèque.');
        } finally {
            $workspace->delete();
        }
        $this->assertDirectoryDoesNotExist($workspace->root, 'Dossier de travail (plan, fichiers temporaires de Remotion) supprimé.');
    }

    public function test_an_invalid_plan_is_refused_by_the_renderer_without_crashing(): void
    {
        config(['video.remotion.path' => storage_path('framework/testing/remotion-absent')]);
        $workspace = RenderWorkspace::create('00000000-0000-0000-0000-00000000a052');
        try {
            $this->expectException(RenderFailure::class);
            app(RemotionRenderer::class)->render(app(ClipPlanner::class)->plan(['start_ms' => 0, 'end_ms' => 5_000], AspectRatio::Vertical), null, $workspace);
        } finally {
            $workspace->delete();
        }
    }
}
