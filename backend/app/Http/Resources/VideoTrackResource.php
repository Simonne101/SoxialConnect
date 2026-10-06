<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Piste de timeline (calque) et, si chargés, ses éléments dans l'ordre du temps. */
class VideoTrackResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'name' => $this->name,
            'position' => $this->position,
            'locked' => $this->locked,
            'muted' => $this->muted,
            'hidden' => $this->hidden,
            'metadata' => $this->metadata ?? (object) [],
            'items' => VideoTimelineItemResource::collection($this->whenLoaded('items')),
        ];
    }
}
