<?php

namespace App\Models;

use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\VideoProjectKind;
use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Enums\VideoSourceType;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\VideoProjectFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Projet vidéo (ADR-042) :
 * - « composition » (Video Studio, ADR-044) : scènes, assets, pistes et éléments de timeline → rendus MP4 ;
 * - « clipping » : découpage d'une vidéo longue (source_media_id OU source_url obligatoire,
 *   validé côté application).
 * user_id, status, lock_version, ai_generation_id, last_render_id, width, height, fps et
 * duration_ms (fin de la timeline) ne sont jamais assignés en masse : fixés par les services.
 */
class VideoProject extends Model
{
    /** @use HasFactory<VideoProjectFactory> */
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['kind', 'source_type', 'source_media_id', 'source_url', 'title', 'description', 'language', 'aspect_ratio', 'target_duration_ms', 'settings'];

    protected function casts(): array
    {
        return [
            'kind' => VideoProjectKind::class,
            'status' => VideoProjectStatus::class,
            'source_type' => VideoSourceType::class,
            'aspect_ratio' => AspectRatio::class,
            'target_duration_ms' => 'integer',
            'duration_ms' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'fps' => 'integer',
            'settings' => 'array',
            'lock_version' => 'integer',
        ];
    }

    public function sourceMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'source_media_id');
    }

    /** Source vidéo du Clipper (ADR-049) : référence YouTube, acquisition, fichier rendable. */
    public function videoSource(): HasOne
    {
        return $this->hasOne(VideoSource::class);
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(VideoTranscript::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(VideoClip::class);
    }

    /** Génération IA (script) dont le projet est issu. */
    public function aiGeneration(): BelongsTo
    {
        return $this->belongsTo(AiGeneration::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(VideoScene::class)->orderBy('position');
    }

    /** Pistes de timeline, de l'arrière-plan (position 0) au premier plan. */
    public function tracks(): HasMany
    {
        return $this->hasMany(VideoTrack::class)->orderBy('position');
    }

    /** Médias de la médiathèque rattachés au projet. */
    public function assets(): HasMany
    {
        return $this->hasMany(VideoProjectAsset::class);
    }

    public function timelineItems(): HasManyThrough
    {
        return $this->hasManyThrough(VideoTimelineItem::class, VideoTrack::class);
    }

    public function renderJobs(): HasMany
    {
        return $this->hasMany(VideoRenderJob::class);
    }

    public function lastRender(): BelongsTo
    {
        return $this->belongsTo(VideoRenderJob::class, 'last_render_id');
    }
}
