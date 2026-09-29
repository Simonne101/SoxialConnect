<?php

namespace App\Http\Resources;

use App\Domain\Media\Storage\MediaStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Média — format aligné sur frontend/src/types/media.ts. Jamais de chemin physique ni de disque :
 * uniquement des URLs signées courtes, et seulement pour un média utilisable (prêt, non en quarantaine).
 */
class MediaResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $usable = $this->isUsable();
        $storage = app(MediaStorage::class);

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'scan_status' => $this->scan_status->value,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'extension' => $this->extension,
            'size_bytes' => $this->size_bytes,
            'width' => $this->width,
            'height' => $this->height,
            'duration_ms' => $this->duration_ms,
            'folder_id' => $this->folder_id,
            'tags' => $this->tags ?? [],
            'is_gif' => $this->isGif(),
            'usable' => $usable,
            'url' => $usable ? $storage->temporaryUrl($this->resource, MediaStorage::ORIGINAL) : null,
            'thumbnail_url' => $usable ? $storage->temporaryUrl($this->resource, MediaStorage::THUMBNAIL) : null,
            'download_url' => $usable ? $storage->temporaryUrl($this->resource, MediaStorage::ORIGINAL, download: true) : null,
            'failure_reason' => $this->failure_reason,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
