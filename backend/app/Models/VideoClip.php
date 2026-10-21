<?php

namespace App\Models;

use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\ClipSource;
use App\Domain\Video\Enums\ClipStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Clip d'un projet de clipping (ADR-046) ; duration_ms est une colonne générée (end_ms - start_ms),
 * jamais supérieure à 60 000 ms (contrainte CHECK video_clips_max_duration_check).
 * status, source, score, output_media_id, error_* et dates : fixés par les services, jamais en masse.
 */
class VideoClip extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'topic', 'reason', 'start_ms', 'end_ms', 'aspect_ratio', 'subtitles_enabled', 'subtitle_style'];

    protected function casts(): array
    {
        return [
            'aspect_ratio' => AspectRatio::class,
            'source' => ClipSource::class,
            'status' => ClipStatus::class,
            'subtitles_enabled' => 'boolean',
            'subtitle_style' => 'array',
            'score' => 'decimal:2',
            'confidence' => 'integer',
            'score_breakdown' => 'array',
            'start_ms' => 'integer',
            'end_ms' => 'integer',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function outputMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'output_media_id');
    }
}
