<?php

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/** Accès à un(e) compte social : réservé à son propriétaire (refus en 404, protection IDOR). */
class SocialAccountPolicy
{
    use ChecksOwnership;

    /** Les listes sont toujours filtrées par propriétaire (scope ownedBy). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, SocialAccount $socialAccount): Response
    {
        return $this->owns($user, $socialAccount);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function update(User $user, SocialAccount $socialAccount): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $socialAccount);
    }

    public function delete(User $user, SocialAccount $socialAccount): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $socialAccount);
    }
}
