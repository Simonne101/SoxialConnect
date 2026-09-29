<?php

namespace App\Policies;

use App\Models\AiGeneration;
use App\Models\User;
use App\Policies\Concerns\ChecksOwnership;
use Illuminate\Auth\Access\Response;

/** Accès à un(e) génération IA : réservé à son propriétaire (refus en 404, protection IDOR). */
class AiGenerationPolicy
{
    use ChecksOwnership;

    /** Les listes sont toujours filtrées par propriétaire (scope ownedBy). */
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AiGeneration $aiGeneration): Response
    {
        return $this->owns($user, $aiGeneration);
    }

    public function create(User $user): bool
    {
        return ! $user->isSuspended();
    }

    public function update(User $user, AiGeneration $aiGeneration): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $aiGeneration);
    }

    public function delete(User $user, AiGeneration $aiGeneration): Response
    {
        return $user->isSuspended() ? Response::deny('Compte suspendu.') : $this->owns($user, $aiGeneration);
    }
}
