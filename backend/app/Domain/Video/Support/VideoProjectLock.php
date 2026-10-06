<?php

namespace App\Domain\Video\Support;

use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Exceptions\VideoConflict;
use App\Models\VideoProject;

/**
 * Verrou d'écriture d'un projet vidéo (même principe que PostLock) : verrou pessimiste
 * (SELECT … FOR UPDATE) + version optimiste (lock_version). À appeler dans une transaction.
 * Toute modification du projet ou de sa timeline passe par ce verrou : les contrôles de
 * chevauchement et de limites sont faits sans course entre deux requêtes.
 */
final class VideoProjectLock
{
    public static function acquire(VideoProject $project, ?int $expectedVersion = null): VideoProject
    {
        $locked = VideoProject::query()->whereKey($project->getKey())->lockForUpdate()->firstOrFail();

        if ($locked->kind !== VideoProjectKind::Composition) {
            throw VideoConflict::notComposition();
        }
        if ($expectedVersion !== null && $expectedVersion !== $locked->lock_version) {
            throw VideoConflict::staleVersion();
        }

        return $locked;
    }

    /** Nouvelle version du projet après une modification (projet ou timeline). */
    public static function bump(VideoProject $project): void
    {
        $project->lock_version++;
        $project->save();
    }
}
