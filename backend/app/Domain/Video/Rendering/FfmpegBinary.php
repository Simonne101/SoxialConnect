<?php

namespace App\Domain\Video\Rendering;

/**
 * Binaires FFmpeg et FFprobe (config video.binaries : nom dans le PATH ou chemin absolu).
 * Détection : `<binaire> -version`, sans shell, délai video.binary_check_timeout_seconds (un premier
 * lancement peut prendre plusieurs secondes sous Windows : analyse antivirus). Aucune installation.
 * Règles de mémorisation et d'états : ExecutableCheck (succès partagé, échec jamais partagé, délai
 * dépassé = non vérifié et non « absent »).
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

    /**
     * Vérification partagée (ExecutableCheck) : un démarrage lent n'est jamais un « absent », et une requête web
     * ne peut plus dépasser max_execution_time pendant ces contrôles.
     */
    private function check(string $binary, string $signature): bool
    {
        $result = app(ExecutableCheck::class)->check(
            str_starts_with($signature, 'ffprobe') ? 'ffprobe' : 'ffmpeg',
            $binary,
            ['-hide_banner', '-version'],
            fn (string $output) => str_starts_with($output, $signature) ? trim((string) strtok($output, "\n")) : null,
            max(5, (int) config('video.binary_check_timeout_seconds', 20)),
        );

        return $result['state'] !== ExecutableCheck::UNAVAILABLE;
    }
}
