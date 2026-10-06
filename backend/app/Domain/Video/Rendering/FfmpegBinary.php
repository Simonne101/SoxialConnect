<?php

namespace App\Domain\Video\Rendering;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Binaires FFmpeg et FFprobe (config video.binaries : nom dans le PATH ou chemin absolu).
 * Détection : `<binaire> -version`, sans shell, délai video.binary_check_timeout_seconds (un premier
 * lancement peut prendre plusieurs secondes sous Windows : analyse antivirus). Aucune installation.
 * Binaire présent : mis en cache 5 minutes ; absent : 30 s seulement, pour qu'un échec passager
 * (démarrage lent) ou une installation récente ne bloque pas les rendus pendant 5 minutes.
 * Non finale : les tests la remplacent pour simuler la présence ou l'absence des binaires.
 */
class FfmpegBinary
{
    public function ffmpegPath(): string
    {
        return (string) config('video.binaries.ffmpeg');
    }

    public function ffprobePath(): string
    {
        return (string) config('video.binaries.ffprobe');
    }

    /** FFmpeg utilisable (production d'une vidéo). */
    public function isAvailable(): bool
    {
        return $this->check($this->ffmpegPath(), 'ffmpeg version');
    }

    /** FFprobe utilisable (analyse des sources et validation du MP4). */
    public function isProbeAvailable(): bool
    {
        return $this->check($this->ffprobePath(), 'ffprobe version');
    }

    private function check(string $binary, string $signature): bool
    {
        $key = 'video:binary-available:'.hash('sha256', $binary);
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $process = new Process([$binary, '-hide_banner', '-version']);
            $process->setTimeout((int) config('video.binary_check_timeout_seconds'));
            $process->run();
            $available = $process->isSuccessful() && str_starts_with($process->getOutput(), $signature);
        } catch (Throwable) {
            $available = false; // binaire introuvable (Windows : CreateProcess échoue), droits, délai.
        }

        Cache::put($key, $available, $available ? 300 : 30);

        return $available;
    }
}
