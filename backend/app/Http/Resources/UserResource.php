<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Utilisateur courant — aucun secret (mot de passe, 2FA, remember token) n'est exposé. */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'headline' => $this->headline,
            'bio' => $this->bio,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'two_factor_enabled' => $this->hasTwoFactorEnabled(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
