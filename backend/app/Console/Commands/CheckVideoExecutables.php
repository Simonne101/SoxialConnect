<?php

namespace App\Console\Commands;

use App\Domain\Video\Rendering\ExecutableCheck;
use App\Domain\Video\Rendering\FfmpegBinary;
use App\Domain\Video\Sources\YtDlp\YtDlpBinary;
use Illuminate\Console\Command;

/**
 * Vérifie FFmpeg, FFprobe et yt-dlp en CLI (planifiée toutes les 10 minutes) : un succès est partagé avec
 * l'API, qui n'exécute jamais de binaire (ExecutableCheck). Sans --force, un binaire déjà vérifié pour ce
 * fichier n'est pas relancé : la commande est quasi gratuite. Aucun accès réseau.
 */
class CheckVideoExecutables extends Command
{
    protected $signature = 'video:check-executables {--force : revérifie même un binaire déjà vérifié}';

    protected $description = 'Vérifie FFmpeg, FFprobe et yt-dlp (sans réseau) et partage le résultat avec l\'API';

    public function handle(FfmpegBinary $ffmpeg, YtDlpBinary $ytDlp, ExecutableCheck $checks): int
    {
        if ($this->option('force')) {
            $checks->forget('ffmpeg', $ffmpeg->ffmpegPath());
            $checks->forget('ffprobe', $ffmpeg->ffprobePath());
            $ytDlp->forget();
        }
        $results = [
            'FFmpeg' => $ffmpeg->isAvailable(),
            'FFprobe' => $ffmpeg->isProbeAvailable(),
        ];
        if (config('video.sources.yt_dlp.enabled')) {
            $results['yt-dlp'] = $ytDlp->isAvailable();
        }
        foreach ($results as $name => $available) {
            $this->components->twoColumnDetail($name, $available ? 'utilisable' : 'inutilisable');
        }

        return in_array(false, $results, true) ? self::FAILURE : self::SUCCESS;
    }
}
