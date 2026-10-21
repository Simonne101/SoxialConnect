<?php

namespace App\Http\Controllers\Api\V1\Video\Concerns;

use App\Models\VideoProject;

/**
 * Après une écriture dans un projet vidéo, la réponse transmet sa nouvelle version et la durée de
 * la timeline : l'éditeur reste à jour sans recharger tout le projet.
 */
trait RespondsWithProjectVersion
{
    /** @return array{lock_version: int, duration_ms: int} */
    protected function projectMeta(string $projectId): array
    {
        $project = VideoProject::query()->findOrFail($projectId, ['id', 'lock_version', 'duration_ms']);

        return ['lock_version' => (int) $project->lock_version, 'duration_ms' => (int) $project->duration_ms];
    }
}
