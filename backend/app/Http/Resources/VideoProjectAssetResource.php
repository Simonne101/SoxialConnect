<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Média rattaché à un projet vidéo. « available » est faux si le média a été supprimé de la
 * médiathèque ou n'est plus utilisable : il doit alors être remplacé avant un rendu.
 */
class VideoProjectAssetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $media = $this->relationLoaded('media') ? $this->media : null;

        return [
            'id' => $this->id,
            'media_id' => $this->media_id,
            'available' => $media !== null && $media->isUsable(),
            'media' => $this->whenLoaded('media', fn () => $media ? new MediaResource($media) : null),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
