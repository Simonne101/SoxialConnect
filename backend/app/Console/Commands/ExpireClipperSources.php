<?php

namespace App\Console\Commands;

use App\Domain\Video\Sources\VideoSourceService;
use Illuminate\Console\Command;

/**
 * Purge des fichiers vidéo acquis automatiquement pour le Clipper (ADR-049) une fois leur durée de
 * conservation dépassée (video.sources.retention_days). Les fichiers importés par l'utilisateur et
 * ceux en cours d'utilisation ne sont jamais touchés ; les clips générés restent dans la médiathèque.
 */
class ExpireClipperSources extends Command
{
    protected $signature = 'clipper:expire-sources';

    protected $description = 'Purge les sources vidéo du Clipper acquises automatiquement et expirées';

    public function handle(VideoSourceService $sources): int
    {
        $count = $sources->expireDue();
        $this->components->info("{$count} source(s) expirée(s).");

        return self::SUCCESS;
    }
}
