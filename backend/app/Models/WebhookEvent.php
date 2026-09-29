<?php

namespace App\Models;

use App\Domain\Social\Enums\WebhookEventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Événement entrant d'une plateforme, dédoublonné par idempotency_key (docs/security.md §10). */
class WebhookEvent extends Model
{
    use HasUuids;

    protected $fillable = [
        'social_platform_id',
        'social_account_id',
        'external_event_id',
        'idempotency_key',
        'event_type',
        'signature_valid',
        'payload',
        'received_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => WebhookEventStatus::class,
            'signature_valid' => 'boolean',
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(SocialPlatform::class, 'social_platform_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
