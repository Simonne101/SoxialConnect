<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Scène d'un projet vidéo : segment narratif ordonné ; fond éventuel = média de la médiathèque (URL signée). */
class VideoSceneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'position' => $this->position,
            'name' => $this->name,
            'role' => $this->role->value,
            'duration_ms' => $this->duration_ms,
            'media_id' => $this->media_id,
            'media_type' => $this->media_type?->value,
            'media_start_ms' => $this->media_start_ms,
            'media' => $this->whenLoaded('media', fn () => $this->media ? new MediaResource($this->media) : null),
            'fit' => $this->fit->value,
            'on_screen_text' => $this->on_screen_text,
            'voiceover_text' => $this->voiceover_text,
            'visual_prompt' => $this->visual_prompt,
            'transition' => $this->transition->value,
            'settings' => $this->settings ?? (object) [],
        ];
    }
}
