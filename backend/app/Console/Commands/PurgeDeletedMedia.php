<?php

namespace App\Console\Commands;

use App\Domain\Media\Storage\MediaStorage;
use App\Models\Media;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;

/**
 * Purge physique des médias supprimés depuis plus de N jours (docs/database.md §5) :
 * les fichiers sont effacés du stockage ; la ligne est supprimée définitivement si aucune
 * publication n'y fait plus référence (post_media, ON DELETE RESTRICT), sinon conservée
 * en suppression logique (historique des publications).
 */
#[Signature('media:purge-deleted {--days= : Âge minimal de la suppression, en jours}')]
#[Description('Efface les fichiers des médias supprimés depuis plus de N jours')]
class PurgeDeletedMedia extends Command
{
    public function handle(MediaStorage $storage): int
    {
        $days = (int) ($this->option('days') ?? config('media.purge_after_days', 30));
        $purged = 0;
        $kept = 0;

        Media::onlyTrashed()
            ->where('deleted_at', '<', now()->subDays($days))
            ->orderBy('deleted_at')
            ->chunkById(100, function ($batch) use ($storage, &$purged, &$kept) {
                foreach ($batch as $media) {
                    $storage->deleteAll($media);
                    try {
                        $media->forceDelete();
                        $purged++;
                    } catch (QueryException) {
                        $kept++; // encore référencé par une publication : on garde la ligne, sans fichier
                    }
                }
            });

        $this->info("Médias purgés : {$purged} ; lignes conservées (référencées par une publication) : {$kept}.");

        return self::SUCCESS;
    }
}
