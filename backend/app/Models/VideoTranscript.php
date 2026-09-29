<?php

namespace App\Models;

use App\Domain\Video\Enums\TranscriptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VideoTranscript extends Model
{
    use HasUuids;

    protected $fillable = ['video_project_id', 'language', 'provider', 'status', 'segments', 'full_text'];

    protected function casts(): array
    {
        return ['status' => TranscriptStatus::class, 'segments' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class, 'video_project_id');
    }
}
