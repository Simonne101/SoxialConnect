<?php

namespace App\Models;

use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\ClipSource;
use App\Domain\Video\Enums\ClipStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Clip d'un projet vidéo ; duration_ms est une colonne générée (end_ms - start_ms). */
class VideoClip extends Model
{
    use HasUuids;

    protected $fillable = ['title', 'start_ms', 'end_ms', 'aspect_ratio', 'subtitles_enabled', 'subtitle_style'];

    protected function casts(): array
    {
        return [
            'aspect_ratio' => AspectRatio::class,
            'source' => ClipSource::class,
            'status' => ClipStatus::class,
            'subtitles_enabled' => 'boolean',
            'subtitle_style' => 'array',
            'score' => 'decimal:2',
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
