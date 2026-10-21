<?php

namespace App\Domain\Video\Clipper;

use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Rendering\RenderFailure;
use App\Domain\Video\Rendering\RenderWorkspace;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Piste audio de la vidéo source (ADR-046, ADR-047) : mono, 16 kHz, bornée à la durée maximale
 * analysée. Formats : AAC .m4a à faible débit (fournisseur IA, limite de taille) ou WAV PCM 16 bits
 * (whisper.cpp local). Arguments en tableau (aucun shell), dans le dossier de travail.
 */
class AudioExtractor
{
    public function __construct(private readonly FfmpegBinary $binaries) {}

    /**
     * @param  'm4a'|'wav'  $format
     *
     * @throws RenderFailure
     */
    public function extract(string $sourcePath, RenderWorkspace $workspace, string $format = 'm4a'): string
    {
        $output = $workspace->path('work', 'audio.'.($format === 'wav' ? 'wav' : 'm4a'));
        $maxSeconds = (string) ((int) config('video.clipper.max_source_minutes', 120) * 60);
        $codec = $format === 'wav'
            ? ['-c:a', 'pcm_s16le']
            : ['-c:a', 'aac', '-b:a', (string) config('video.clipper.audio_bitrate', '24k')];

        $process = new Process([
            $this->binaries->ffmpegPath(), '-hide_banner', '-nostdin', '-y', '-loglevel', 'error',
            '-protocol_whitelist', 'file', '-i', $sourcePath,
            '-vn', '-ac', '1', '-ar', (string) config('video.clipper.audio_sample_rate', 16_000),
            ...$codec,
            '-t', $maxSeconds, $output,
        ], $workspace->path('work'));
        $process->setTimeout(900);

        try {
            $process->run();
        } catch (Throwable $e) {
            Log::warning('clipper.audio_extraction_failed', ['exception' => $e::class]);
            throw new RenderFailure('AUDIO_EXTRACTION_FAILED', 'La piste audio de la vidéo n\'a pas pu être extraite.');
        }
        if (! $process->isSuccessful() || ! is_file($output) || filesize($output) === 0) {
            Log::warning('clipper.audio_extraction_failed', ['exit_code' => $process->getExitCode(), 'stderr' => mb_substr($process->getErrorOutput(), -500)]);
            throw new RenderFailure('AUDIO_EXTRACTION_FAILED', 'La piste audio de la vidéo n\'a pas pu être extraite.');
        }

        return $output;
    }
}
