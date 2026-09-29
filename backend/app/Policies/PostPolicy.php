<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/** Accès à un(e) publication : réservé à son propriétaire (refus en 404, protection IDOR). */
class PostPolicy
{
    use ChecksOwnership;

    /** Les listes sont toujours filtrées par propriétaire (scope ownedBy). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Post $post): Response
    {
        return $this->owns($user, $post);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function update(User $user, Post $post): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $post);
    }

    /** Dupliquer = lire la source + créer une publication. */
    public function duplicate(User $user, Post $post): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $post);
    }

    public function delete(User $user, Post $post): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $post);
    }
}
