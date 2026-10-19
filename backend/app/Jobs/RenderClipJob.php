<?php

namespace App\Jobs;

use App\Domain\Video\Clipper\ClipRenderRunner;
use App\Models\VideoClip;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Rendu d'un clip ≤ 60 s du YouTube Clipper (ADR-046) par le moteur FFmpeg existant, publié dans la
 * médiathèque. File « video », connexion redis-video, une seule exécution (comme RenderVideoJob).
 */
class RenderClipJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $clipId)
    {
        $this->onConnection(config('video.render.connection'));
        $this->onQueue(config('video.render.queue'));
    }

    public function handle(ClipRenderRunner $runner): void
    {
        $runner->run($this->clipId);
    }

    public function failed(?Throwable $exception): void
    {
        $clip = VideoClip::query()->find($this->clipId);
        if ($clip !== null) {
            app(ClipRenderRunner::class)->fail($clip, 'RENDER_ABORTED', 'La génération du clip a été interrompue.');
        }
        File::deleteDirectory(rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.$this->clipId);
    }
}
