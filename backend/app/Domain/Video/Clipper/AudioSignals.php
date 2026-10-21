<?php

namespace App\Domain\Video\Clipper;

use App\Domain\Video\Rendering\FfmpegBinary;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Pauses d'une piste audio (FFmpeg silencedetect), pour l'analyse locale sans IA (ADR-047).
 * Arguments en tableau (aucun shell) ; la sortie de FFmpeg n'est ni journalisée ni renvoyée.
 */
class AudioSignals
{
    public function __construct(private readonly FfmpegBinary $binaries) {}

    /**
     * Intervalles de silence, en millisecondes. Liste vide si la détection échoue (l'analyse se fait
     * alors sans pauses) ou si l'audio ne contient aucune pause.
     *
     * @return list<array{0: int, 1: int}>
     */
    public function silences(string $audioPath, int $durationMs, string $workDir): array
    {
        $noise = (int) config('video.clipper.silence.noise_db', -35);
        $min = (float) config('video.clipper.silence.min_seconds', 0.6);
        $process = new Process([
            $this->binaries->ffmpegPath(), '-hide_banner', '-nostdin', '-loglevel', 'info',
            '-protocol_whitelist', 'file', '-i', $audioPath,
            '-af', "silencedetect=noise={$noise}dB:d={$min}", '-f', 'null', '-',
        ], $workDir);
        $process->setTimeout(600);
        try {
            $process->run();
        } catch (Throwable) {
            return [];
        }
        if (! $process->isSuccessful()) {
            return [];
        }

        return self::parse($process->getErrorOutput(), $durationMs);
    }

    /** @return list<array{0: int, 1: int}> */
    public static function parse(string $log, int $durationMs): array
    {
        $silences = [];
        $start = null;
        foreach (preg_split('/\R/', $log) ?: [] as $line) {
            if (preg_match('/silence_start:\s*(-?[\d.]+)/', $line, $m)) {
                $start = max(0, (int) round((float) $m[1] * 1000));
            } elseif ($start !== null && preg_match('/silence_end:\s*([\d.]+)/', $line, $m)) {
                $silences[] = [$start, min($durationMs, (int) round((float) $m[1] * 1000))];
                $start = null;
            }
        }
        if ($start !== null && $start < $durationMs) {
            $silences[] = [$start, $durationMs]; // silence jusqu'à la fin
        }

        return $silences;
    }
}
