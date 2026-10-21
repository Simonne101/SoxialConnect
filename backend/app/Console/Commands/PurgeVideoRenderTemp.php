<?php

namespace App\Console\Commands;

use App\Domain\Video\Enums\RenderStatus;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Models\VideoRenderJob;
use App\Models\VideoSource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Filet de sécurité (ADR-045) : supprime les dossiers de travail de rendus abandonnés (worker
 * arrêté brutalement), plus anciens que video.temp_retention_hours. Un dossier d'un rendu encore
 * actif n'est jamais supprimé. Les médias source ne sont jamais concernés.
 * Également (ADR-056) : restes d'une acquisition Google Data Portability interrompue — archives
 * téléchargées (portability-<source>) et vidéo extraite (acquired-<source>.<ext>) — sauf si cette
 * acquisition est encore en cours. Et (ADR-059) : dossier de téléchargement yt-dlp (ytdlp-<source>)
 * laissé par un worker arrêté brutalement.
 */
class PurgeVideoRenderTemp extends Command
{
    protected $signature = 'video:purge-temp';

    protected $description = 'Supprime les dossiers temporaires de rendus vidéo abandonnés';

    public function handle(): int
    {
        $root = (string) config('video.temp_path');
        if (! is_dir($root)) {
            $this->info('Aucun dossier temporaire.');

            return self::SUCCESS;
        }

        $limit = now()->subHours((int) config('video.temp_retention_hours', 24))->getTimestamp();
        $active = VideoRenderJob::query()->whereIn('status', [RenderStatus::Queued->value, RenderStatus::Rendering->value])->pluck('id')->all();
        $purged = 0;
        foreach (File::directories($root) as $dir) {
            $id = basename($dir);
            if (! preg_match('/^[0-9a-f-]{36}$/i', $id) || in_array($id, $active, true) || filemtime($dir) > $limit) {
                continue;
            }
            File::deleteDirectory($dir);
            $purged++;
        }
        $this->info("Dossiers de rendu supprimés : {$purged}.");

        $acquisitions = 0;
        $activeSources = VideoSource::query()->whereIn('status', [VideoSourceStatus::Pending->value, VideoSourceStatus::Resolving->value, VideoSourceStatus::WaitingForProvider->value])->pluck('id')->all();
        foreach ([...File::directories($root), ...File::files($root)] as $entry) {
            $path = (string) $entry;
            if (! preg_match('/^(?:(?:portability|ytdlp)-([0-9a-f-]{36})|acquired-([0-9a-f-]{36})\.[a-z0-9]{2,5})$/i', basename($path), $m)) {
                continue;
            }
            $sourceId = $m[1] !== '' ? $m[1] : $m[2];
            if (in_array($sourceId, $activeSources, true) || filemtime($path) > $limit) {
                continue;
            }
            is_dir($path) ? File::deleteDirectory($path) : File::delete($path);
            $acquisitions++;
        }
        $this->info("Restes d'acquisition supprimés : {$acquisitions}.");

        return self::SUCCESS;
    }
}
