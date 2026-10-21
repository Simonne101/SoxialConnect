<?php

namespace App\Models;

use App\Domain\Video\Enums\TimelineItemType;
use Database\Factories\VideoTimelineItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Élément posé sur une piste de timeline (ADR-044). Temps en millisecondes.
 * Fin sur la timeline : start_ms + duration_ms. Propriété : celle du projet de la piste.
 * La piste et l'asset sont fixés par VideoTimelineService (validation du projet et du type).
 */
class VideoTimelineItem extends Model
{
    /** @use HasFactory<VideoTimelineItemFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['type', 'start_ms', 'duration_ms', 'source_start_ms', 'source_duration_ms', 'properties', 'transitions'];

    protected function casts(): array
    {
        return [
            'type' => TimelineItemType::class,
            'start_ms' => 'integer',
            'duration_ms' => 'integer',
            'source_start_ms' => 'integer',
            'source_duration_ms' => 'integer',
            'properties' => 'array',
            'transitions' => 'array',
        ];
    }

    public function endMs(): int
    {
        return $this->start_ms + $this->duration_ms;
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(VideoTrack::class, 'video_track_id');
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(VideoScene::class, 'video_scene_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(VideoProjectAsset::class, 'video_project_asset_id');
    }
}
