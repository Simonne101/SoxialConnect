<?php

namespace App\Console\Commands;

use App\Domain\Video\Enums\RenderStatus;
use App\Models\VideoRenderJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Filet de sécurité (ADR-045) : supprime les dossiers de travail de rendus abandonnés (worker
 * arrêté brutalement), plus anciens que video.temp_retention_hours. Un dossier d'un rendu encore
 * actif n'est jamais supprimé. Les médias source ne sont jamais concernés.
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

        return self::SUCCESS;
    }
}
