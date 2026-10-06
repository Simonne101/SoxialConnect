<?php

namespace App\Models;

use App\Domain\Video\Enums\VideoTrackType;
use Database\Factories\VideoTrackFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Piste de timeline d'un projet « composition » (ADR-044). position = ordre des calques
 * (0 = arrière-plan). Propriété : celle du projet. Le type n'est pas modifiable une fois
 * des éléments posés (VideoTimelineService).
 */
class VideoTrack extends Model
{
    /** @use HasFactory<VideoTrackFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['type', 'name', 'position', 'locked', 'muted', 'hidden', 'metadata'];

    protected function casts(): array
    {
        return [
            'type' => VideoTrackType::class,
            'position' => 'integer',
            'locked' => 'boolean',
            'muted' => 'boolean',
            'hidden' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(VideoTimelineItem::class)->orderBy('start_ms');
    }
}
