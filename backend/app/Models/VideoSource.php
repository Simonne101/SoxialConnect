<?php

namespace App\Models;

use App\Domain\Video\Enums\AcquisitionMethod;
use App\Domain\Video\Enums\SourceOwnership;
use App\Domain\Video\Enums\VideoSourceStatus;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Source vidéo d'un projet du Clipper (ADR-049) : référence YouTube (provider + external_reference),
 * propriété vérifiée, voie d'acquisition et fichier rendable (media_id) une fois obtenu.
 * Aucun attribut n'est assignable en masse : tout est fixé par VideoSourceService.
 */
class VideoSource extends Model
{
    use BelongsToUser, HasUuids;

    public const PROVIDER_YOUTUBE = 'youtube';

    public const TYPE_YOUTUBE_VIDEO = 'youtube_video';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'ownership_status' => SourceOwnership::class,
            'acquisition_method' => AcquisitionMethod::class,
            'status' => VideoSourceStatus::class,
            'ownership_checked_at' => 'datetime',
            'next_check_at' => 'datetime',
            'purge_at' => 'datetime',
            'duration_ms' => 'integer',
            'size_bytes' => 'integer',
            'attempts' => 'integer',
            'metadata' => 'array',
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

    public function ownershipAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'ownership_account_id');
    }

    public function isReady(): bool
    {
        return $this->status === VideoSourceStatus::Ready && $this->media_id !== null;
    }
}
