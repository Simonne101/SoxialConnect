<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoClip;
use App\Policies\Concerns\ChecksVideoProjectAccess;
use Illuminate\Auth\Access\Response;

/** Clip du YouTube Clipper : accès du propriétaire du projet uniquement (refus en 404, protection IDOR). */
class VideoClipPolicy
{
    use ChecksVideoProjectAccess;

    public function view(User $user, VideoClip $clip): Response
    {
        return $this->readsProject($user, $clip->project);
    }

    public function update(User $user, VideoClip $clip): Response
    {
        return $this->writesProject($user, $clip->project);
    }
}
