<?php

namespace App\Models;

use App\Domain\AI\Enums\AiGenerationStatus;
use App\Domain\AI\Enums\AiTask;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historique des générations IA (fournisseur interchangeable, ADR-011). */
class AiGeneration extends Model
{
    use BelongsToUser, HasUuids;

    protected $fillable = ['post_id', 'video_project_id', 'task', 'input'];

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'status' => AiGenerationStatus::class,
            'input' => 'array',
            'output' => 'array',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }
}
