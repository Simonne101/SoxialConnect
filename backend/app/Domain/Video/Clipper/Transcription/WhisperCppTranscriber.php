<?php

namespace App\Domain\Video\Clipper\Transcription;

use App\Domain\AI\Data\AiTranscriptionResult;
use App\Domain\Video\Rendering\RenderWorkspace;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Transcription LOCALE par whisper.cpp (ADR-047) : aucune API, aucun réseau. Le binaire whisper-cli
 * et le modèle ggml sont configurés dans video.clipper.whisper (par défaut dans storage/app/whisper).
 * Entrée : WAV 16 kHz mono ; sortie JSON complète (-ojf) : segments horodatés en ms, langue détectée
 * et mots horodatés reconstitués à partir des tokens (recalage des passages, ADR-049).
 * Arguments en tableau (aucun shell), exécution dans le dossier de travail du traitement.
 */
class WhisperCppTranscriber implements Transcriber
{
    public function mode(): string
    {
        return 'local';
    }

    public function engine(): string
    {
        return 'whisper.cpp';
    }

    public function model(): string
    {
        return pathinfo((string) config('video.clipper.whisper.model'), PATHINFO_FILENAME);
    }

    public function audioFormat(): string
    {
        return 'wav';
    }

    public function maxDurationMs(): int
    {
        return max(1, (int) config('video.clipper.whisper.max_minutes', 20)) * 60_000;
    }

    /** Binaire et modèle présents et lisibles (aucune exécution). */
    public function isInstalled(): bool
    {
        $binary = (string) config('video.clipper.whisper.binary');
        $model = (string) config('video.clipper.whisper.model');

        return $binary !== '' && is_file($binary) && $model !== '' && is_file($model) && is_readable($model) && filesize($model) > 0;
    }

    public function transcribe(string $audioPath, RenderWorkspace $workspace): AiTranscriptionResult
    {
        if (! $this->isInstalled()) {
            throw new TranscriptionFailure('LOCAL_TRANSCRIPTION_UNAVAILABLE', 'La transcription locale (whisper.cpp) n\'est pas installée sur ce serveur.');
        }
        $language = (string) config('video.clipper.whisper.language', 'auto');
        $outputBase = $workspace->path('work', 'transcript');

        $process = new Process([
            (string) config('video.clipper.whisper.binary'),
            '-m', (string) config('video.clipper.whisper.model'),
            '-f', $audioPath,
            '-l', preg_match('/^[a-z]{2}$|^auto$/', $language) ? $language : 'auto',
            '-t', (string) max(1, (int) config('video.clipper.whisper.threads', 4)),
            '-ojf', '-of', $outputBase, '-np',
        ], $workspace->path('work'));
        $process->setTimeout((int) config('video.clipper.whisper.timeout', 1500));

        $started = hrtime(true);
        try {
            $process->run();
        } catch (Throwable $e) {
            Log::warning('clipper.whisper_failed', ['exception' => $e::class]);
            throw new TranscriptionFailure('LOCAL_TRANSCRIPTION_FAILED', 'La transcription locale a échoué (délai dépassé ou moteur indisponible).');
        }
        if (! $process->isSuccessful() || ! is_file($outputBase.'.json')) {
            Log::warning('clipper.whisper_failed', ['exit_code' => $process->getExitCode(), 'stderr' => mb_substr($process->getErrorOutput(), -500)]);
            throw new TranscriptionFailure('LOCAL_TRANSCRIPTION_FAILED', 'La transcription locale a échoué.');
        }

        return self::parse((string) file_get_contents($outputBase.'.json'), $this->model(), (int) round((hrtime(true) - $started) / 1_000_000));
    }

    /**
     * Sortie JSON de whisper-cli (-oj ou -ojf) → résultat normalisé (segments vides ignorés).
     *
     * Avec -ojf, un caractère accentué peut être coupé entre deux tokens (octets UTF-8 incomplets) :
     * le fichier est donc lu octet par octet (ISO-8859-1 → UTF-8, toujours décodable), puis les octets
     * d'origine de chaque texte sont reconstitués avant d'être validés en UTF-8.
     */
    public static function parse(string $json, string $model, int $latencyMs): AiTranscriptionResult
    {
        $data = json_decode(mb_check_encoding($json, 'UTF-8') ? $json : '', true);
        $raw = false;
        if (! is_array($data)) {
            $data = json_decode(mb_convert_encoding($json, 'UTF-8', 'ISO-8859-1'), true);
            $raw = true;
        }
        if (! is_array($data) || ! is_array($data['transcription'] ?? null)) {
            throw new TranscriptionFailure('LOCAL_TRANSCRIPTION_FAILED', 'Résultat de transcription locale illisible.');
        }
        $segments = [];
        foreach ($data['transcription'] as $segment) {
            $from = $segment['offsets']['from'] ?? null;
            $to = $segment['offsets']['to'] ?? null;
            $text = trim(self::text((string) ($segment['text'] ?? ''), $raw));
            // Marqueurs de non-parole de Whisper ([Musique], (applaudissements)…) : ignorés.
            if (! is_int($from) || ! is_int($to) || $to < $from || $text === '' || preg_match('/^[\[(].*[\])]$/u', $text)) {
                continue;
            }
            $words = self::words((array) ($segment['tokens'] ?? []), $raw);
            $segments[] = ['start_ms' => $from, 'end_ms' => $to, 'text' => $text, ...($words !== [] ? ['words' => $words] : [])];
        }
        $language = $data['result']['language'] ?? null;

        return new AiTranscriptionResult(
            provider: 'whisper.cpp',
            model: $model,
            text: implode(' ', array_column($segments, 'text')),
            language: is_string($language) ? $language : null,
            segments: $segments,
            latencyMs: $latencyMs,
        );
    }

    /** Texte d'origine : octets reconstitués si le fichier a été lu en ISO-8859-1, UTF-8 garanti. */
    private static function text(string $text, bool $raw): string
    {
        $text = $raw ? mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8') : $text;

        return mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');
    }

    /**
     * Mots horodatés à partir des tokens de whisper.cpp : un mot commence par un token précédé d'une
     * espace ; ses octets sont réassemblés avant décodage (accents coupés entre deux tokens).
     * Marqueurs spéciaux ([_BEG_], [_TT_…]) ignorés.
     *
     * @param  array<int, mixed>  $tokens
     * @return list<array{0: int, 1: int, 2: string}> [début ms, fin ms, mot]
     */
    private static function words(array $tokens, bool $raw): array
    {
        $words = [];
        $current = null;
        foreach ($tokens as $token) {
            $from = $token['offsets']['from'] ?? null;
            $to = $token['offsets']['to'] ?? null;
            $bytes = (string) ($token['text'] ?? '');
            $bytes = $raw ? mb_convert_encoding($bytes, 'ISO-8859-1', 'UTF-8') : $bytes;
            if (! is_int($from) || ! is_int($to) || $to < $from || $bytes === '' || str_starts_with($bytes, '[_')) {
                continue;
            }
            if ($current === null || str_starts_with($bytes, ' ')) {
                if ($current !== null) {
                    $words[] = $current;
                }
                $current = [$from, $to, $bytes];
            } else {
                $current[1] = $to;
                $current[2] .= $bytes;
            }
        }
        if ($current !== null) {
            $words[] = $current;
        }

        return array_values(array_filter(array_map(function (array $word) {
            $text = trim($word[2]);
            $text = mb_check_encoding($text, 'UTF-8') ? $text : mb_scrub($text, 'UTF-8');

            return [$word[0], $word[1], $text];
        }, $words), fn (array $word) => $word[2] !== '' && ! preg_match('/^[\[(].*[\])]$/u', $word[2])));
    }
}
