<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoScene;
use App\Policies\Concerns\ChecksVideoProjectAccess;
use Illuminate\Auth\Access\Response;

/** Scène d'un projet vidéo : accès du propriétaire du projet vidéo uniquement (refus en 404, protection IDOR). */
class VideoScenePolicy
{
    use ChecksVideoProjectAccess;

    public function view(User $user, VideoScene $scene): Response
    {
        return $this->readsProject($user, $scene->project);
    }

    public function update(User $user, VideoScene $scene): Response
    {
        return $this->writesProject($user, $scene->project);
    }

    public function delete(User $user, VideoScene $scene): Response
    {
        return $this->writesProject($user, $scene->project);
    }
}
