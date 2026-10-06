<?php

namespace App\Models;

use App\Domain\AI\Enums\AiGenerationStatus;
use App\Domain\AI\Enums\AiTask;
use App\Models\Concerns\BelongsToUser;
use Database\Factories\AiGenerationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Historique des générations IA (fournisseur interchangeable, ADR-011) et suivi d'usage (ADR-042). */
class AiGeneration extends Model
{
    /** @use HasFactory<AiGenerationFactory> */
    use BelongsToUser, HasFactory, HasUuids;

    protected $fillable = ['post_id', 'video_project_id', 'task', 'input'];

    protected function casts(): array
    {
        return [
            'task' => AiTask::class,
            'status' => AiGenerationStatus::class,
            'input' => 'array',
            'output' => 'array',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cost_estimate_micros' => 'integer',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** Projet vidéo analysé par cette génération (clipping : analyse, détection, transcription). */
    public function videoProject(): BelongsTo
    {
        return $this->belongsTo(VideoProject::class);
    }

    /** Projets vidéo créés à partir de cette génération (script → composition). */
    public function videoProjects(): HasMany
    {
        return $this->hasMany(VideoProject::class, 'ai_generation_id');
    }
}
