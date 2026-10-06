<?php

namespace App\Policies\Concerns;

use App\Models\User;
use App\Models\VideoProject;
use Illuminate\Auth\Access\Response;

/**
 * Accès aux éléments d'un projet vidéo (scènes, pistes, éléments de timeline, assets) : celui du
 * projet (ADR-044). Projet d'un autre compte ou supprimé : 404, sans révéler l'existence.
 */
trait ChecksVideoProjectAccess
{
    use ChecksOwnership;

    protected function readsProject(User $user, ?VideoProject $project): Response
    {
        return $project === null ? Response::denyAsNotFound() : $this->owns($user, $project);
    }

    protected function writesProject(User $user, ?VideoProject $project): Response
    {
        $read = $this->readsProject($user, $project);

        return $read->allowed() && $user->isSuspended() ? Response::deny('Compte suspendu.') : $read;
    }
}
