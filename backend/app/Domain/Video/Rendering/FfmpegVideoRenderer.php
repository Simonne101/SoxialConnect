<?php

namespace App\Domain\Video\Rendering;

use App\Models\Media;
use App\Models\VideoProjectAsset;
use App\Models\VideoRenderJob;
use Closure;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Moteur de rendu FFmpeg (ADR-045). À partir de la spécification FIGÉE d'un rendu :
 * 1. sources résolues côté serveur et revérifiées (propriétaire du rendu, rattachées au projet,
 *    utilisables, fichier présent et lisible) ;
 * 2. analyse FFprobe (flux attendus présents, extrait dans la durée du média) ;
 * 3. commande construite par FfmpegCommandBuilder, exécutée sans shell dans le dossier de travail ;
 * 4. progression lue sur -progress (out_time), annulation vérifiée chaque seconde : FFmpeg est
 *    alors arrêté (arbre de processus) ;
 * 5. MP4 validé par FFprobe (conteneur MP4, H.264, AAC si du son est attendu, durée cohérente).
 * Ne publie rien : le résultat validé est remis à VideoRenderService.
 *
 * Seul moteur vidéo de SoxialConnect : le YouTube Clipper (ADR-046) l'utilise aussi, avec une
 * spécification au même format (produce()) dont il a lui-même résolu et vérifié la source.
 */
class FfmpegVideoRenderer
{
    private const CANCEL_CHECK_SECONDS = 1.0;

    public function __construct(
        private readonly FfmpegBinary $binaries,
        private readonly MediaProbe $probe,
        private readonly FfmpegCommandBuilder $builder,
    ) {}

    /**
     * @param  Closure(int): void  $onProgress  pourcentage indicatif (0-99) pendant l'encodage
     * @param  Closure(): bool  $shouldCancel  vrai si le rendu a été annulé entre-temps
     *
     * @throws RenderFailure
     */
    public function render(VideoRenderJob $render, RenderWorkspace $workspace, Closure $onProgress, Closure $shouldCancel): RenderedVideo
    {
        $this->ensureAvailable();

        $spec = (array) $render->spec;
        if (($spec['schema'] ?? null) !== 'soxial.render_spec.v1' || (int) ($spec['project']['duration_ms'] ?? 0) <= 0) {
            throw new RenderFailure('INVALID_TIMELINE', 'La timeline de ce rendu est vide ou invalide.');
        }

        [$sources, $hasAudio] = $this->sources($render, $spec, $workspace);

        return $this->produce($spec, $sources, $hasAudio, $workspace, $onProgress, $shouldCancel);
    }

    /** @throws RenderFailure FFmpeg ou FFprobe absent */
    public function ensureAvailable(): void
    {
        if (! $this->binaries->isAvailable() || ! $this->binaries->isProbeAvailable()) {
            throw new RenderFailure('RENDERER_UNAVAILABLE', 'FFmpeg ou FFprobe n\'est pas disponible sur le serveur : le rendu vidéo ne peut pas être produit.');
        }
    }

    /**
     * Pipeline commun : commande (FfmpegCommandBuilder), exécution, validation FFprobe, vignette.
     * Les sources doivent déjà être résolues ET vérifiées par l'appelant (propriétaire, fichier).
     *
     * @param  array<string, mixed>  $spec  spécification soxial.render_spec.v1
     * @param  array<string, string>  $sources  chemins locaux par identifiant de média
     * @param  array<string, bool>  $hasAudio  présence de son par identifiant de média
     * @param  Closure(int): void  $onProgress
     * @param  Closure(): bool  $shouldCancel
     *
     * @throws RenderFailure
     */
    public function produce(array $spec, array $sources, array $hasAudio, RenderWorkspace $workspace, Closure $onProgress, Closure $shouldCancel): RenderedVideo
    {
        $output = $workspace->path('output', 'video.mp4');
        $command = $this->builder->forVersion($this->binaries->version())->build($spec, $sources, $hasAudio, $output);

        foreach ($command->files as $name => $contents) {
            file_put_contents($workspace->path('work', $name), $contents);
        }
        if ($command->needsFont) {
            copy($this->fontFile(), $workspace->path('work', FfmpegCommandBuilder::FONT_FILE));
        }

        $this->run($command, $workspace, $onProgress, $shouldCancel);

        return $this->validate($output, $command, $spec, $workspace);
    }

    /**
     * @param  array<string, mixed>  $spec
     * @return array{0: array<string, string>, 1: array<string, bool>} chemins locaux et présence de son par média
     */
    private function sources(VideoRenderJob $render, array $spec, RenderWorkspace $workspace): array
    {
        $items = collect($spec['tracks'])->flatMap(fn ($track) => $track['items'])->filter(fn ($item) => $item['media_id'] !== null);
        $ids = $items->pluck('media_id')->unique()->values();

        // Revérification serveur : médias du propriétaire du rendu, encore rattachés au projet.
        $media = Media::query()->where('user_id', $render->user_id)->whereKey($ids)->get()->keyBy('id');
        $attached = VideoProjectAsset::query()->where('video_project_id', $render->video_project_id)->whereIn('media_id', $ids)->pluck('media_id')->all();

        $paths = [];
        $hasAudio = [];
        foreach ($ids as $id) {
            $file = $media->get($id);
            if ($file === null || ! in_array($id, $attached, true) || ! $file->isUsable()) {
                throw new RenderFailure('SOURCE_MISSING', 'Un média de la timeline n\'est plus disponible.');
            }
            $path = $this->sourcePath($file, $workspace);
            $paths[$id] = $path;

            $uses = $items->where('media_id', $id);
            if ($uses->contains(fn ($i) => in_array($i['type'], ['video', 'audio'], true))) {
                $probe = $this->probe->probe($path);
                if ($uses->contains(fn ($i) => $i['type'] === 'video') && ! $probe->hasVideo) {
                    throw new RenderFailure('SOURCE_INVALID', 'Un média utilisé comme vidéo ne contient pas d\'image.');
                }
                if ($uses->contains(fn ($i) => $i['type'] === 'audio') && ! $probe->hasAudio) {
                    throw new RenderFailure('SOURCE_INVALID', 'Un média utilisé comme son ne contient pas d\'audio.');
                }
                foreach ($uses as $use) {
                    $end = (int) ($use['source_start_ms'] ?? 0) + (int) ($use['source_duration_ms'] ?? $use['duration_ms']);
                    if ($probe->durationMs !== null && $end > $probe->durationMs + 50) {
                        throw new RenderFailure('SOURCE_RANGE', 'Un extrait dépasse la durée de son média source.');
                    }
                }
                $hasAudio[$id] = $probe->hasAudio;
            }
        }

        return [$paths, $hasAudio];
    }

    /**
     * Fichier local lisible d'un média (disque local : en place ; sinon copié dans input/). Le
     * média doit déjà avoir été vérifié (propriétaire, utilisable) par l'appelant.
     *
     * @throws RenderFailure
     */
    public function sourcePath(Media $file, RenderWorkspace $workspace): string
    {
        $disk = Storage::disk((string) config('media.disk', 'media'));
        if (config('filesystems.disks.'.config('media.disk', 'media').'.driver') === 'local') {
            $path = $disk->path($file->path);
        } else {
            $path = $workspace->path('input', $file->id.'.'.($file->extension ?: 'bin'));
            $stream = $disk->readStream($file->path);
            if ($stream === null || file_put_contents($path, $stream) === false) {
                throw new RenderFailure('SOURCE_MISSING', 'Un fichier source est introuvable.');
            }
        }
        if (! is_file($path) || ! is_readable($path) || filesize($path) === 0) {
            throw new RenderFailure('SOURCE_MISSING', 'Un fichier source est introuvable ou illisible.');
        }

        return $path;
    }

    private function fontFile(): string
    {
        foreach (array_filter([config('video.font_file'), ...(array) config('video.font_candidates')]) as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new RenderFailure('FONT_UNAVAILABLE', 'Aucune police n\'est disponible sur le serveur pour afficher les textes (VIDEO_FONT_FILE).');
    }

    private function run(BuiltCommand $command, RenderWorkspace $workspace, Closure $onProgress, Closure $shouldCancel): void
    {
        $process = new Process([$this->binaries->ffmpegPath(), ...$command->arguments], $workspace->path('work'));
        $process->setTimeout((int) config('video.render.timeout_seconds', 1800) - 60);

        $buffer = '';
        $lastCheck = 0.0;
        $lastProgress = -1;
        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $buffer .= $process->getIncrementalOutput();
                if (preg_match_all('/^out_time_us=(\d+)$/m', $buffer, $m)) {
                    // Indicatif : temps encodé / durée finale, plafonné à 99 % avant validation.
                    $encodedMs = intdiv((int) end($m[1]), 1000);
                    $percent = min(99, intdiv($encodedMs * 100, max(1, $command->durationMs)));
                    if ($percent > $lastProgress) {
                        $lastProgress = $percent;
                        $onProgress($lastProgress);
                    }
                    $buffer = '';
                }
                if (microtime(true) - $lastCheck >= self::CANCEL_CHECK_SECONDS) {
                    $lastCheck = microtime(true);
                    if ($shouldCancel()) {
                        // Arrêt réel : Symfony Process arrête l'arbre de processus (taskkill /T sous Windows).
                        $process->stop(3);
                        throw RenderFailure::cancelled();
                    }
                }
                usleep(200_000);
            }
        } catch (ProcessTimedOutException) {
            $process->stop(3);
            throw new RenderFailure('RENDER_TIMEOUT', 'Le rendu a dépassé la durée maximale autorisée.');
        } catch (RenderFailure $e) {
            throw $e;
        } catch (Throwable $e) {
            $process->stop(3);
            Log::error('video.render_process_failed', ['exception' => $e::class]);
            throw new RenderFailure('FFMPEG_FAILED', 'FFmpeg n\'a pas pu produire la vidéo.');
        }

        if (! $process->isSuccessful()) {
            // Sortie d'erreur journalisée côté serveur seulement (elle peut citer des chemins).
            Log::warning('video.render_ffmpeg_error', ['exit_code' => $process->getExitCode(), 'stderr' => mb_substr($process->getErrorOutput(), -1000)]);
            throw new RenderFailure('FFMPEG_FAILED', 'FFmpeg n\'a pas pu produire la vidéo.');
        }
    }

    /** @param array<string, mixed> $spec */
    private function validate(string $output, BuiltCommand $command, array $spec, RenderWorkspace $workspace): RenderedVideo
    {
        if (! is_file($output) || filesize($output) === 0) {
            throw new RenderFailure('OUTPUT_INVALID', 'La vidéo produite est vide.');
        }
        $probe = $this->probe->probe($output);
        $fps = (int) $spec['project']['fps'];
        $tolerance = max(250, (int) ceil(2000 / max(1, $fps)));
        $valid = str_contains($probe->formatName, 'mp4')
            && $probe->hasVideo && $probe->videoCodec === 'h264'
            && $probe->width === (int) $spec['project']['width'] && $probe->height === (int) $spec['project']['height']
            && (! $command->hasAudio || ($probe->hasAudio && $probe->audioCodec === 'aac'))
            && $probe->durationMs !== null && abs($probe->durationMs - $command->durationMs) <= $tolerance;
        if (! $valid) {
            Log::warning('video.render_output_invalid', ['format' => $probe->formatName, 'video' => $probe->videoCodec, 'audio' => $probe->audioCodec, 'duration_ms' => $probe->durationMs]);
            throw new RenderFailure('OUTPUT_INVALID', 'La vidéo produite n\'a pas passé la validation.');
        }

        return new RenderedVideo($output, $this->thumbnail($output, $probe, $workspace), $probe, (int) filesize($output));
    }

    /**
     * MP4 produit HORS du moteur FFmpeg (composition Remotion du Clipper, ADR-051) : validé par FFprobe comme un
     * rendu interne (conteneur MP4, piste vidéo, durée mesurée ≤ $maxDurationMs) puis vignette. Aucun
     * nouveau rendu : le fichier est publié tel quel s'il est valide.
     *
     * @throws RenderFailure
     */
    public function adopt(string $path, RenderWorkspace $workspace, int $maxDurationMs): RenderedVideo
    {
        $this->ensureAvailable();
        $probe = $this->probe->probe($path);
        if (! $probe->hasVideo || $probe->durationMs === null || ! str_contains($probe->formatName, 'mp4')) {
            throw new RenderFailure('OUTPUT_INVALID', 'Le fichier reçu n\'est pas une vidéo MP4 lisible.');
        }
        if ($probe->durationMs > $maxDurationMs) {
            throw new RenderFailure('OUTPUT_TOO_LONG', 'Le clip reçu dépasse la durée maximale autorisée.');
        }

        return new RenderedVideo($path, $this->thumbnail($path, $probe, $workspace), $probe, (int) filesize($path));
    }

    /** Vignette JPEG (au mieux : son absence n'empêche pas la publication). */
    private function thumbnail(string $video, ProbeResult $probe, RenderWorkspace $workspace): ?string
    {
        $thumb = $workspace->path('output', 'thumbnail.jpg');
        $at = number_format(min(1.0, (($probe->durationMs ?? 0) / 1000) / 2), 3, '.', '');
        try {
            $process = new Process([$this->binaries->ffmpegPath(), '-hide_banner', '-nostdin', '-y', '-loglevel', 'error', '-ss', $at, '-protocol_whitelist', 'file', '-i', $video, '-frames:v', '1', '-vf', 'scale=480:-2', $thumb]);
            $process->setTimeout(60);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        return $process->isSuccessful() && is_file($thumb) && filesize($thumb) > 0 ? $thumb : null;
    }
}
