<?php

namespace App\Domain\Video\Rendering;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * Mesures audio réelles d'un fichier (aucune modification) : durées des flux audio et vidéo (FFprobe) et
 * niveau sonore moyen/crête (FFmpeg volumedetect). Sert à refuser un clip dont le son a été perdu ou rendu
 * inaudible par le traitement alors que la source en contient (incident du 2026-10-13 : volume 1 % → −40 dB).
 */
class AudioCheck
{
    public function __construct(private readonly FfmpegBinary $binaries) {}

    /**
     * Durées (ms) du premier flux vidéo et du premier flux audio, null si absent ou illisible.
     *
     * @return array{video: ?int, audio: ?int}
     */
    public function streamDurations(string $path): array
    {
        $durations = ['video' => null, 'audio' => null];
        try {
            $process = new Process([$this->binaries->ffprobePath(), '-v', 'error', '-protocol_whitelist', 'file', '-show_entries', 'stream=codec_type,duration', '-of', 'json', $path]);
            $process->setTimeout((int) config('video.probe_timeout_seconds', 30));
            $process->run();
            foreach ((array) (json_decode($process->getOutput(), true)['streams'] ?? []) as $stream) {
                $type = $stream['codec_type'] ?? null;
                if (in_array($type, ['video', 'audio'], true) && $durations[$type] === null && is_numeric($stream['duration'] ?? null)) {
                    $durations[$type] = (int) round((float) $stream['duration'] * 1000);
                }
            }
        } catch (Throwable) {
            // mesure impossible : null (le contrôle appelant décide)
        }

        return $durations;
    }

    /**
     * Niveau sonore (dBFS) : moyenne et crête, sur tout le fichier ou sur un passage ; null sans audio mesurable.
     *
     * @return array{mean: float, max: float}|null
     */
    public function loudness(string $path, ?int $startMs = null, ?int $durationMs = null): ?array
    {
        $range = $startMs !== null && $durationMs !== null ? ['-ss', sprintf('%.3f', $startMs / 1000), '-t', sprintf('%.3f', $durationMs / 1000)] : [];
        try {
            $process = new Process([$this->binaries->ffmpegPath(), '-hide_banner', '-nostdin', '-nostats', ...$range, '-protocol_whitelist', 'file', '-i', $path, '-vn', '-af', 'volumedetect', '-f', 'null', '-']);
            $process->setTimeout(max(60, (int) config('video.probe_timeout_seconds', 30) * 4));
            $process->run();
        } catch (Throwable) {
            return null;
        }
        $log = $process->getErrorOutput();
        if (! preg_match('/mean_volume:\s*(-?[\d.]+|-inf) dB/', $log, $mean) || ! preg_match('/max_volume:\s*(-?[\d.]+|-inf) dB/', $log, $max)) {
            return null;
        }
        $db = fn (string $v) => $v === '-inf' ? -120.0 : (float) $v;

        return ['mean' => $db($mean[1]), 'max' => $db($max[1])];
    }
}
