<?php

namespace App\Models;

use App\Domain\Video\Enums\VideoProjectStatus;
use App\Domain\Video\Enums\VideoSourceType;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Projet de clipping (phase Video Studio). source_media_id OU source_url obligatoire (validé côté application). */
class VideoProject extends Model
{
    use BelongsToUser, HasUuids, SoftDeletes;

    protected $fillable = ['source_type', 'source_media_id', 'source_url', 'title', 'language'];

    protected function casts(): array
    {
        return [
            'status' => VideoProjectStatus::class,
            'source_type' => VideoSourceType::class,
        ];
    }

    public function sourceMedia(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'source_media_id');
    }

    public function transcripts(): HasMany
    {
        return $this->hasMany(VideoTranscript::class);
    }

    public function clips(): HasMany
    {
        return $this->hasMany(VideoClip::class);
    }
}
