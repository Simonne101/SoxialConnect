<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Propriété d'une ressource racine (ADR-007).
 * Toute requête filtrée par propriétaire passe par ownedBy() : le jour où les workspaces
 * arriveront, seul ce point (et les Policies) changera.
 */
trait BelongsToUser
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where($this->getTable().'.user_id', $user->getKey());
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->getAttribute('user_id') === $user->getKey();
    }
}
