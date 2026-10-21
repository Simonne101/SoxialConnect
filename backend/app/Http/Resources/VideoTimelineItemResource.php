<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Élément de timeline (temps en millisecondes). Le média se lit via l'asset du projet. */
class VideoTimelineItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'video_track_id' => $this->video_track_id,
            'video_scene_id' => $this->video_scene_id,
            'video_project_asset_id' => $this->video_project_asset_id,
            'type' => $this->type->value,
            'start_ms' => $this->start_ms,
            'duration_ms' => $this->duration_ms,
            'end_ms' => $this->start_ms + $this->duration_ms,
            'source_start_ms' => $this->source_start_ms,
            'source_duration_ms' => $this->source_duration_ms,
            'properties' => $this->properties ?? (object) [],
            'transitions' => $this->transitions,
        ];
    }
}
