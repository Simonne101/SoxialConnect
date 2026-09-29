<?php

namespace App\Models;

use App\Domain\Publishing\Enums\AttemptStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tentative de publication d'une variante (phase Publishing). */
class PublicationAttempt extends Model
{
    use HasUuids;

    protected $fillable = [
        'post_variant_id',
        'attempt_number',
        'idempotency_key',
        'status',
        'retryable',
        'job_uuid',
        'http_status',
        'error_code',
        'error_message',
        'response_excerpt',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => AttemptStatus::class,
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
