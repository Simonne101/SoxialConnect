<?php

namespace App\Models;

use App\Domain\Analytics\Enums\SnapshotScope;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Réponse brute d'une plateforme (conservée 13 mois), normalisée dans AnalyticsMetric. */
class AnalyticsSnapshot extends Model
{
    use HasUuids;

    protected $fillable = ['social_account_id', 'post_variant_id', 'scope', 'period_start', 'period_end', 'api_version', 'payload', 'fetched_at'];

    protected function casts(): array
    {
        return [
            'scope' => SnapshotScope::class,
            'payload' => 'array',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
