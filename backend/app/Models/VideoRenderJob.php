<?php

namespace App\Models;

use App\Domain\Video\Enums\AspectRatio;
use App\Domain\Video\Enums\RenderStatus;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\VideoRenderJobFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rendu MP4 d'un projet « composition » (ADR-042, exécuté par RenderVideoJob : ADR-045).
 * La base garantit que user_id est celui du propriétaire du projet (clé étrangère composite) et
 * qu'un seul rendu est actif (queued ou rendering) par projet.
 * Aucun champ n'est assignable en masse : le futur RenderService fixe chaque valeur explicitement.
 */
class VideoRenderJob extends Model
{
    /** @use HasFactory<VideoRenderJobFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'status' => RenderStatus::class,
            'aspect_ratio' => AspectRatio::class,
            'progress' => 'integer',
            'spec' => 'array',
            'width' => 'integer',
            'height' => 'integer',
            'fps' => 'integer',
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
