<?php

namespace Tests\Feature\Video;

use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Le moteur de rendu transmet la version RÉELLE de FFmpeg au constructeur de commande (ADR-077) : preuve par
 * exécution, avec le FFmpeg installé. Une version annoncée « 6.1 » doit produire la syntaxe ancienne
 * (-filter_complex_script), qu'un FFmpeg 8 ou plus refuse ; la version réelle doit produire une vidéo valide.
 * La syntaxe ancienne elle-même est exécutée pour de bon par la CI (Ubuntu 24.04, FFmpeg 6.1).
 */
#[Group('ffmpeg')]
class FfmpegVersionCompatibilityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        if (! app(FfmpegBinary::class)->isAvailable() || ! app(FfmpegBinary::class)->isProbeAvailable()) {
            $this->markTestSkipped('FFmpeg/FFprobe introuvables : test d\'exécution réelle non exécuté.');
        }
        config(['video.temp_path' => storage_path('framework/testing/ffmpeg-version')]);
    }

    /** FFmpeg réel, mais version annoncée imposée. */
    private function announce(?string $version): void
    {
        $this->app->instance(FfmpegBinary::class, new class($version) extends FfmpegBinary
        {
            public function __construct(private readonly ?string $announced) {}

            public function version(): ?string
            {
                return $this->announced ?? parent::version();
            }
        });
        $this->app->forgetInstance(FfmpegVideoRenderer::class);
    }

    private function render(): void
    {
        $spec = ['schema' => 'soxial.render_spec.v1', 'project' => ['width' => 320, 'height' => 240, 'fps' => 10, 'duration_ms' => 1000], 'tracks' => []];
        $workspace = RenderWorkspace::create((string) Str::uuid());
        try {
            app(FfmpegVideoRenderer::class)->produce($spec, [], [], $workspace, fn () => null, fn () => false);
        } finally {
            $workspace->delete();
        }
    }

    public function test_the_real_ffmpeg_version_produces_a_valid_video(): void
    {
        $this->announce(null);

        $this->render();

        $this->assertMatchesRegularExpression('/^ffmpeg version /', (string) app(FfmpegBinary::class)->version(), 'Version lue depuis le binaire.');
    }

    public function test_an_announced_older_version_switches_the_command_syntax(): void
    {
        $real = (string) app(FfmpegBinary::class)->version();
        preg_match('/version\s+n?(\d+)\./', $real, $m);
        if ((int) ($m[1] ?? 0) < 8) {
            $this->markTestSkipped('FFmpeg réel antérieur à 8 : la syntaxe ancienne y est acceptée (cas couvert par la CI).');
        }
        $this->announce('ffmpeg version 6.1.1-3ubuntu5');
        Log::spy();

        try {
            $this->render();
            $this->fail('FFmpeg 8+ aurait dû refuser la syntaxe ancienne : la version annoncée n\'a pas été prise en compte.');
        } catch (RenderFailure $e) {
            $this->assertSame('FFMPEG_FAILED', $e->errorCode);
        }
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => $message === 'video.render_ffmpeg_error'
            && str_contains((string) ($context['stderr'] ?? ''), 'filter_complex_script'))->once();
    }
}
