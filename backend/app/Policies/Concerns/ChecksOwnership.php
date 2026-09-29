<?php

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Contrôle de propriété commun aux ressources utilisateur (protection IDOR, docs/security.md §4).
 * Refus rendu en 404 : on ne révèle pas l'existence d'une ressource appartenant à un autre compte.
 */
trait ChecksOwnership
{
    protected function owns(User $user, Model $model): Response
    {
        return $model->getAttribute('user_id') === $user->getKey()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
