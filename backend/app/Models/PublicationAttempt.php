<?php

namespace App\Models;

use App\Domain\Publishing\Enums\AttemptStatus;
use App\Domain\Publishing\Enums\AttemptTrigger;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tentative de publication d'une variante : une ligne par envoi, jamais modifiée après sa
 * conclusion ni supprimée par une relance (historique complet, docs/publishing.md §5).
 */
class PublicationAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'post_variant_id',
        'social_account_id',
        'attempt_number',
        'trigger',
        'triggered_by',
        'idempotency_key',
        'status',
        'retryable',
        'job_uuid',
        'http_status',
        'error_code',
        'error_message',
        'external_post_id',
        'response_excerpt',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
            'trigger' => AttemptTrigger::class,
            'attempt_number' => 'integer',
            'retryable' => 'boolean',
            'response_excerpt' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }
}
