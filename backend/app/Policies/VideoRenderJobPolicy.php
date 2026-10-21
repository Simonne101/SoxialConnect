<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoRenderJob;
use App\Policies\Concerns\ChecksVideoProjectAccess;
use Illuminate\Auth\Access\Response;

/** Rendu d'un projet vidéo : accès de son propriétaire uniquement (refus en 404, protection IDOR). */
class VideoRenderJobPolicy
{
    use ChecksVideoProjectAccess;

    public function view(User $user, VideoRenderJob $render): Response
    {
        return $this->owns($user, $render)->allowed() ? $this->readsProject($user, $render->project) : $this->owns($user, $render);
    }

    /** Annulation. */
    public function update(User $user, VideoRenderJob $render): Response
    {
        return $this->owns($user, $render)->allowed() ? $this->writesProject($user, $render->project) : $this->owns($user, $render);
    }
}
