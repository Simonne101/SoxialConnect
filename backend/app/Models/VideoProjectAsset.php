<?php

namespace App\Models;

use App\Models\Concerns\BelongsToUser;
use Database\Factories\VideoProjectAssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Média de la médiathèque rattaché à un projet vidéo (ADR-044) : aucune copie de fichier.
 * La base garantit que le projet et le média appartiennent au même utilisateur (user_id,
 * clés étrangères composites). Un média supprimé de la médiathèque reste rattaché mais
 * « indisponible » (relation media nulle). Aucun champ assignable en masse.
 */
class VideoProjectAsset extends Model
{
    /** @use HasFactory<VideoProjectAssetFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    protected $guarded = ['*'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function timelineItems(): HasMany
    {
        return $this->hasMany(VideoTimelineItem::class);
    }
}
