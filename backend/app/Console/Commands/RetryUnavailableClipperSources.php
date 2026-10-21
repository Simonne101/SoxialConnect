<?php

namespace App\Console\Commands;

use App\Domain\Video\Sources\VideoSourceService;
use Illuminate\Console\Command;

/**
 * Relance la recherche de source des projets du Clipper restés « indisponibles » faute de voie
 * d'acquisition (PROVIDER_NOT_CONFIGURED, NO_PROVIDER_FOR_SOURCE) alors qu'une voie la prend désormais en
 * charge (ex. export Google ou yt-dlp activé après coup) : aucun projet ne reste figé sur un diagnostic périmé. Projets annulés ou déjà
 * dotés d'un fichier : jamais touchés.
 */
class RetryUnavailableClipperSources extends Command
{
    protected $signature = 'clipper:retry-unavailable-sources';

    protected $description = 'Relance la recherche de source des projets du Clipper bloqués faute de voie d\'acquisition, si une voie est désormais disponible';

    public function handle(VideoSourceService $sources): int
    {
        $count = $sources->retryStaleUnavailable();
        $this->components->info("{$count} recherche(s) de source relancée(s).");

        return self::SUCCESS;
    }
}
