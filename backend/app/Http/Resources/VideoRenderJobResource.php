<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Rendu MP4 d'un projet (statut, progression, erreur). La spécification figée n'est pas exposée.
 * Vidéo produite : média de la médiathèque, uniquement via URLs signées (MediaResource), jamais un chemin.
 */
class VideoRenderJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'video_project_id' => $this->video_project_id,
            'status' => $this->status->value,
            'progress' => $this->progress,
            'renderer' => $this->renderer,
            'aspect_ratio' => $this->aspect_ratio?->value,
            'width' => $this->width,
            'height' => $this->height,
            'fps' => $this->fps,
            'output_media_id' => $this->output_media_id,
            'output' => $this->whenLoaded('outputMedia', fn () => $this->outputMedia !== null && $this->outputMedia->isUsable() ? new MediaResource($this->outputMedia) : null),
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
