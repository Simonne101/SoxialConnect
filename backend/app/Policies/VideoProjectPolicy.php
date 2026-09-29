<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VideoProject;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/** Accès à un(e) projet vidéo : réservé à son propriétaire (refus en 404, protection IDOR). */
class VideoProjectPolicy
{
    use ChecksOwnership;

    /** Les listes sont toujours filtrées par propriétaire (scope ownedBy). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VideoProject $videoProject): Response
    {
        return $this->owns($user, $videoProject);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function update(User $user, VideoProject $videoProject): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $videoProject);
    }

    public function delete(User $user, VideoProject $videoProject): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $videoProject);
    }
}
