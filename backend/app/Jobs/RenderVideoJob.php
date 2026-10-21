<?php

namespace App\Jobs;

use App\Domain\Quota\DiskSpaceInsufficient;
use App\Domain\Quota\QuotaExceeded;
use App\Domain\Quota\ResourceGuard;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use App\Domain\Video\VideoRenderService;
use App\Models\VideoRenderJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Exécution d'un rendu MP4 (ADR-045) — file « video », connexion « redis-video »
 * (retry_after 1 900 s > timeout 1 800 s : un rendu en cours n'est jamais redonné à un autre worker).
 *
 * queued → rendering (prise sous verrou : un job dupliqué ou relancé ne fait rien) → FFmpeg
 * (FfmpegVideoRenderer) → MP4 validé par FFprobe → publication → succeeded. Toute erreur donne
 * « failed » avec un code normalisé ; une annulation arrête FFmpeg et ne publie rien. Le dossier de
 * travail est supprimé dans tous les cas.
 */
class RenderVideoJob implements ShouldQueue
{
    use Queueable;

    /** Une seule exécution : jamais de rendu relancé à l'aveugle par la file. */
    public int $tries = 1;

    /** = config video.render.timeout_seconds (vérifié par les tests). */
    public int $timeout = 1800;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $renderId)
    {
        $this->onConnection(config('video.render.connection'));
        $this->onQueue(config('video.render.queue'));
    }

    public function handle(VideoRenderService $renders, FfmpegVideoRenderer $renderer, ResourceGuard $resources): void
    {
        $render = $renders->start($this->renderId);
        if ($render === null) {
            return; // supprimé, annulé ou déjà traité
        }

        // Quotas (ADR-071) : stockage de l'utilisateur et disque réservés AVANT FFmpeg ; sinon échec propre.
        try {
            $release = $resources->forRender((string) $render->user_id, 'video_render');
        } catch (QuotaExceeded $e) {
            $renders->fail($render, $e->errorCode, $e->getMessage());

            return;
        } catch (DiskSpaceInsufficient $e) {
            $renders->fail($render, DiskSpaceInsufficient::CODE, $e->getMessage());

            return;
        }

        $workspace = RenderWorkspace::create($render->getKey());
        try {
            $video = $renderer->render(
                $render,
                $workspace,
                onProgress: fn (int $percent) => $renders->progress($render, $percent),
                shouldCancel: fn () => $renders->isCancelled($render),
            );
            $renders->complete($render, $video);
        } catch (RenderFailure $e) {
            if ($e->errorCode !== 'CANCELLED') {
                $renders->fail($render, $e->errorCode, $e->getMessage());
            }
        } catch (Throwable $e) {
            Log::error('video.render_failed', ['render_id' => $render->getKey(), 'exception' => $e::class]);
            $renders->fail($render, 'RENDER_FAILED', 'Le rendu a échoué de façon inattendue.');
        } finally {
            $workspace->delete();
            $release();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $render = VideoRenderJob::query()->find($this->renderId);
        if ($render !== null) {
            app(VideoRenderService::class)->fail($render, 'RENDER_ABORTED', 'Le rendu a été interrompu.');
        }
        File::deleteDirectory(rtrim((string) config('video.temp_path'), '/\\').DIRECTORY_SEPARATOR.$this->renderId);
    }
}
