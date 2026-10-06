<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Projet Video Studio (ADR-044). Relations incluses seulement si chargées (éditeur). Jamais user_id ni chemin de fichier. */
class VideoProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'aspect_ratio' => $this->aspect_ratio->value,
            'width' => $this->width,
            'height' => $this->height,
            'fps' => $this->fps,
            'duration_ms' => (int) $this->duration_ms,
            'target_duration_ms' => $this->target_duration_ms,
            'settings' => $this->settings ?? (object) [],
            'lock_version' => $this->lock_version,
            'last_render_id' => $this->last_render_id,
            'scenes' => VideoSceneResource::collection($this->whenLoaded('scenes')),
            'tracks' => VideoTrackResource::collection($this->whenLoaded('tracks')),
            'assets' => VideoProjectAssetResource::collection($this->whenLoaded('assets')),
            'renders' => VideoRenderJobResource::collection($this->whenLoaded('renderJobs')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
