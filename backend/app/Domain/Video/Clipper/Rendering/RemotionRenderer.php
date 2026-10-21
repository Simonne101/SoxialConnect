<?php

namespace App\Domain\Video\Clipper\Rendering;

use App\Domain\Video\Clipper\ClipLimits;
use App\Domain\Video\Clipper\Planning\RenderPlan;
use App\Domain\Video\Rendering\FfmpegVideoRenderer;
use App\Domain\Video\Rendering\RenderedVideo;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Composition Remotion d'un clip (ADR-051, ADR-052), isolée du Video Studio : le paquet Node `renderer/`
 * reçoit le RenderPlan et la vidéo du passage (déjà découpée et recadrée par FFmpeg, dans le dossier
 * de travail du rendu) et produit le MP4 final ; la sortie est validée par FFprobe comme tout rendu
 * (FfmpegVideoRenderer::adopt : MP4 lisible, ≤ 60 s) puis comparée au plan (dimensions, durée, son).
 *
 * Sécurité : Node lancé par Symfony Process avec des arguments en tableau (aucun shell) ; le plan et la
 * vidéo restent dans le dossier de travail isolé ; le lanceur ne sert que ce fichier (127.0.0.1,
 * jeton aléatoire) ; fichiers temporaires de Remotion confinés au dossier de travail ; délai maximal ;
 * annulation réelle (processus arrêté) ; aucune sortie technique renvoyée à l'utilisateur.
 */
class RemotionRenderer
{
    /** Écart toléré entre la durée produite et la durée prévue (≈ 2 images à 30 i/s). */
    private const DURATION_TOLERANCE_MS = 120;

    public function __construct(private readonly FfmpegVideoRenderer $ffmpeg) {}

    /** Paquet installé (node_modules) et point d'entrée présent : aucune exécution. */
    public function isAvailable(): bool
    {
        $root = $this->root();

        return is_file($root.'/render.mjs') && is_dir($root.'/node_modules/remotion') && is_dir($root.'/node_modules/@remotion/renderer');
    }

    /**
     * @param  string|null  $videoPath  vidéo du passage, DANS le dossier de travail ; null : fond seul
     * @param  bool  $expectAudio  la vidéo du passage a une piste audio : le MP4 final doit la garder
     * @param  (Closure(): bool)|null  $shouldCancel  vrai si le rendu a été annulé entre-temps
     * @param  (Closure(int): void)|null  $onProgress  avancement réel de Remotion (0-100 %)
     *
     * @throws RenderFailure
     */
    public function render(RenderPlan $plan, ?string $videoPath, RenderWorkspace $workspace, ?Closure $shouldCancel = null, bool $expectAudio = false, ?Closure $onProgress = null): RenderedVideo
    {
        if (! $this->isAvailable()) {
            throw new RenderFailure('REMOTION_UNAVAILABLE', 'Le moteur de composition des clips n\'est pas installé sur ce serveur.');
        }
        if ($videoPath !== null && (! is_file($videoPath) || ! str_starts_with(realpath($videoPath) ?: '', realpath($workspace->root) ?: "\0"))) {
            throw new RenderFailure('SOURCE_INVALID', 'La vidéo du passage est introuvable.');
        }
        $propsFile = $workspace->path('work', 'remotion-plan.json');
        $output = $workspace->path('output', 'remotion.mp4');
        $temp = $workspace->path('work', 'remotion-tmp');
        if (! is_dir($temp)) {
            mkdir($temp, 0700, true);
        }
        file_put_contents($propsFile, json_encode($plan->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $arguments = [(string) config('video.remotion.node_binary', 'node'), 'render.mjs', '--props', $propsFile, '--out', $output];
        if ($videoPath !== null) {
            array_push($arguments, '--video', $videoPath, '--root', $workspace->root);
        }
        $process = new Process($arguments, $this->root(), array_filter([
            'TEMP' => $temp,
            'TMP' => $temp,
            'REMOTION_BROWSER_EXECUTABLE' => config('video.remotion.browser_executable'),
            'REMOTION_CONCURRENCY' => (string) max(1, (int) config('video.remotion.concurrency', 2)),
            'REMOTION_GL' => config('video.remotion.gl') ?: null,
            'REMOTION_STEP_TIMEOUT_MS' => (string) (max(10, min(600, (int) config('video.remotion.step_timeout', 120))) * 1000),
        ]));
        $process->setTimeout((int) config('video.remotion.timeout', 1500));

        $this->run($process, $shouldCancel, $onProgress);

        $lines = preg_split('/\R/', trim($process->getOutput())) ?: [];
        $result = json_decode((string) end($lines), true);
        if (is_array($result) && is_array($result['timings'] ?? null)) {
            // Phases mesurées par render.mjs : bundle, sélection de la composition, images, encodage.
            Log::info('clipper.remotion_timings', ['frames' => $result['frames'] ?? null, 'concurrency' => $result['concurrency'] ?? null, 'gl' => $result['gl'] ?? null, ...array_map('intval', array_filter($result['timings'], 'is_numeric'))]);
        }
        if (! $process->isSuccessful() || ! is_array($result) || ($result['ok'] ?? false) !== true || ! is_file($output) || filesize($output) === 0) {
            // Début (message d'erreur de Remotion) et fin (pile) de la sortie d'erreur.
            $stderr = $process->getErrorOutput();
            Log::warning('clipper.remotion_failed', ['exit_code' => $process->getExitCode(), 'code' => is_array($result) ? ($result['code'] ?? null) : null, 'stderr' => mb_strlen($stderr) > 1600 ? mb_substr($stderr, 0, 800).' […] '.mb_substr($stderr, -800) : $stderr]);
            throw new RenderFailure('REMOTION_RENDER_FAILED', 'La composition du clip a échoué.');
        }

        // Même contrôle FFprobe que tout rendu, puis conformité au plan.
        try {
            $video = $this->ffmpeg->adopt($output, $workspace, ClipLimits::MAX_CLIP_MS);
        } catch (RenderFailure $e) {
            // FFprobe ne lit pas le fichier produit : c'est la sortie qui est invalide, pas FFprobe.
            throw $e->errorCode === 'PROBE_FAILED' ? new RenderFailure('OUTPUT_INVALID', 'La composition produite est illisible.') : $e;
        }
        $probe = $video->probe;
        if ($probe->width !== $plan->width || $probe->height !== $plan->height
            || abs((int) $probe->durationMs - $plan->durationMs()) > self::DURATION_TOLERANCE_MS
            || ($expectAudio && ! $probe->hasAudio)) {
            Log::warning('clipper.remotion_output_mismatch', ['width' => $probe->width, 'height' => $probe->height, 'duration_ms' => $probe->durationMs, 'audio' => $probe->hasAudio]);
            throw new RenderFailure('OUTPUT_INVALID', 'La composition produite ne correspond pas au plan (dimensions, durée ou son).');
        }

        return $video;
    }

    /** Exécution surveillée : annulation coopérative (processus arrêté), délai maximal, avancement. */
    private function run(Process $process, ?Closure $shouldCancel, ?Closure $onProgress = null): void
    {
        try {
            $process->start();
            $buffer = '';
            $report = function () use ($process, $onProgress, &$buffer): void {
                if ($onProgress === null) {
                    return;
                }
                $buffer .= $process->getIncrementalOutput();
                while (($eol = strpos($buffer, "\n")) !== false) {
                    $line = json_decode(substr($buffer, 0, $eol), true);
                    $buffer = substr($buffer, $eol + 1);
                    if (is_array($line) && is_numeric($line['progress'] ?? null)) {
                        $onProgress(max(0, min(100, (int) floor((float) $line['progress'] * 100))));
                    }
                }
            };
            while ($process->isRunning()) {
                if ($shouldCancel !== null && $shouldCancel()) {
                    $process->stop(3);

                    throw RenderFailure::cancelled();
                }
                $process->checkTimeout();
                $report();
                usleep(250_000);
            }
            $report(); // lignes écrites juste avant la fin du processus
        } catch (RenderFailure $e) {
            throw $e;
        } catch (ProcessTimedOutException) {
            throw new RenderFailure('REMOTION_TIMEOUT', 'La composition du clip a dépassé le délai autorisé.');
        } catch (Throwable $e) {
            Log::warning('clipper.remotion_failed', ['exception' => $e::class]);
            throw new RenderFailure('REMOTION_RENDER_FAILED', 'La composition du clip a échoué (moteur indisponible).');
        }
    }

    private function root(): string
    {
        return rtrim((string) config('video.remotion.path'), '/\\');
    }
}
