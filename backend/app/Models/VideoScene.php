<?php

namespace App\Models;

use App\Domain\Video\Enums\SceneFit;
use App\Domain\Video\Enums\SceneMediaType;
use App\Domain\Video\Enums\SceneRole;
use App\Domain\Video\Enums\SceneTransition;
use Database\Factories\VideoSceneFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Scène d'un projet « composition » (ADR-042, ADR-044) : segment narratif ordonné (position).
 * Propriété : celle du projet. Le média de fond doit appartenir au propriétaire du projet :
 * règle appliquée par VideoSceneService, la base ne pouvant pas l'exprimer (docs/database.md §3.6).
 */
class VideoScene extends Model
{
    /** @use HasFactory<VideoSceneFactory> */
    use HasFactory, HasUuids;

    protected $fillable = ['position', 'name', 'role', 'duration_ms', 'media_id', 'media_type', 'media_start_ms', 'fit', 'on_screen_text', 'voiceover_text', 'visual_prompt', 'transition', 'settings'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'role' => SceneRole::class,
            'duration_ms' => 'integer',
            'media_type' => SceneMediaType::class,
            'media_start_ms' => 'integer',
            'fit' => SceneFit::class,
            'transition' => SceneTransition::class,
            'settings' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /** Éléments de timeline rattachés à la scène (facultatif). */
    public function timelineItems(): HasMany
    {
        return $this->hasMany(VideoTimelineItem::class);
    }
}
