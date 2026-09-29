<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Publication et ses variantes — format aligné sur frontend/src/types/content.ts. */
class PostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'status' => $this->status->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'timezone' => $this->timezone,
            'source' => $this->source->value,
            'lock_version' => $this->lock_version,
            'media_ids' => $this->whenLoaded('media', fn () => $this->media->pluck('id')->values()),
            'variants' => PostVariantResource::collection($this->whenLoaded('variants')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
