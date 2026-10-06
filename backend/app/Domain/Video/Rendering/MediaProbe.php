<?php

namespace App\Domain\Video\Rendering;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Analyse d'un fichier avec FFprobe (sortie JSON) : format, durée, flux vidéo et audio.
 * Arguments en tableau (aucun shell) ; un fichier illisible ou invalide donne PROBE_FAILED.
 */
class MediaProbe
{
    public function __construct(private readonly FfmpegBinary $binaries) {}

    /** @throws RenderFailure */
    public function probe(string $path): ProbeResult
    {
        try {
            $process = new Process([
                $this->binaries->ffprobePath(), '-v', 'error', '-print_format', 'json', '-show_format', '-show_streams', $path,
            ]);
            $process->setTimeout((int) config('video.probe_timeout_seconds', 30));
            $process->run();
        } catch (Throwable) {
            throw new RenderFailure('PROBE_FAILED', 'Un fichier n\'a pas pu être analysé (FFprobe indisponible ou délai dépassé).');
        }

        $data = json_decode($process->getOutput(), true);
        if (! $process->isSuccessful() || ! is_array($data) || ! isset($data['format'])) {
            throw new RenderFailure('PROBE_FAILED', 'Un fichier source est illisible ou n\'est pas un média valide.');
        }

        $streams = (array) ($data['streams'] ?? []);
        $video = collect($streams)->first(fn ($s) => ($s['codec_type'] ?? null) === 'video' && ! (($s['disposition']['attached_pic'] ?? 0) === 1));
        $audio = collect($streams)->first(fn ($s) => ($s['codec_type'] ?? null) === 'audio');
        $duration = $data['format']['duration'] ?? $video['duration'] ?? $audio['duration'] ?? null;

        return new ProbeResult(
            formatName: (string) ($data['format']['format_name'] ?? ''),
            durationMs: is_numeric($duration) ? (int) round(((float) $duration) * 1000) : null,
            hasVideo: $video !== null,
            hasAudio: $audio !== null,
            videoCodec: $video['codec_name'] ?? null,
            audioCodec: $audio['codec_name'] ?? null,
            width: isset($video['width']) ? (int) $video['width'] : null,
            height: isset($video['height']) ? (int) $video['height'] : null,
            fps: $this->rate($video['avg_frame_rate'] ?? $video['r_frame_rate'] ?? null),
        );
    }

    private function rate(?string $fraction): ?float
    {
        if ($fraction === null || ! str_contains($fraction, '/')) {
            return null;
        }
        [$num, $den] = array_map('floatval', explode('/', $fraction, 2));

        return $den > 0 ? round($num / $den, 3) : null;
    }
}
