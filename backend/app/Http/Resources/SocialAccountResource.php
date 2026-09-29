<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compte social — les tokens OAuth ne sont jamais exposés (docs/security.md §5). */
class SocialAccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->whenLoaded('platform', fn () => $this->platform->key),
            'status' => $this->status->value,
            'account_type' => $this->account_type?->value,
            'display_name' => $this->display_name,
            'username' => $this->username,
            'status_reason' => $this->status_reason,
            // Compte fictif créé par DemoSocialAccountsSeeder (développement local uniquement).
            'is_demo' => $this->isDemo(),
            'connected_at' => $this->connected_at?->toIso8601String(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(),
        ];
    }
}
