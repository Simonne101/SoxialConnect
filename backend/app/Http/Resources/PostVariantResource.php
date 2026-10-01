<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostVariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'social_account_id' => $this->social_account_id,
            'platform' => $this->whenLoaded('socialAccount', fn () => $this->socialAccount->relationLoaded('platform') ? $this->socialAccount->platform->key : null),
            'format' => $this->format->value,
            'body' => $this->body,
            'options' => $this->options ?? (object) [],
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'queued_at' => $this->queued_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'external_url' => $this->external_url,
            'attempts_count' => $this->attempts_count ?? 0,
            'error_code' => $this->last_error_code,
            'error_message' => $this->last_error_message,
        ];
    }
}
