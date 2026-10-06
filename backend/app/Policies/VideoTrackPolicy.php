<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoTrack;
use App\Policies\Concerns\ChecksVideoProjectAccess;
use Illuminate\Auth\Access\Response;

/** Piste de timeline : accès du propriétaire du projet vidéo uniquement (refus en 404, protection IDOR). */
class VideoTrackPolicy
{
    use ChecksVideoProjectAccess;

    public function view(User $user, VideoTrack $track): Response
    {
        return $this->readsProject($user, $track->project);
    }

    public function update(User $user, VideoTrack $track): Response
    {
        return $this->writesProject($user, $track->project);
    }

    public function delete(User $user, VideoTrack $track): Response
    {
        return $this->writesProject($user, $track->project);
    }
}
