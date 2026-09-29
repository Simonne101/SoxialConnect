<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/** Accès à un(e) média : réservé à son propriétaire (refus en 404, protection IDOR). */
class MediaPolicy
{
    use ChecksOwnership;

    /** Les listes sont toujours filtrées par propriétaire (scope ownedBy). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Media $media): Response
    {
        return $this->owns($user, $media);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function update(User $user, Media $media): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $media);
    }

    public function delete(User $user, Media $media): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $media);
    }
}
