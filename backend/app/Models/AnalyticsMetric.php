<?php

namespace App\Models;

use App\Domain\Analytics\Enums\Granularity;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Point de métriques normalisé (table « analytics »). Une métrique NULL signifie
 * « non fournie par la plateforme » et ne doit jamais être affichée comme 0 (ADR-017).
 */
class AnalyticsMetric extends Model
{
    use HasUuids;

    protected $table = 'analytics';

    protected $fillable = [
        'social_account_id', 'post_variant_id', 'snapshot_id', 'metric_date', 'granularity',
        'impressions', 'reach', 'views', 'engagements', 'clicks', 'likes', 'comments', 'shares', 'saves',
        'followers', 'followers_delta', 'extra',
    ];

    protected function casts(): array
    {
        return [
            'granularity' => Granularity::class,
            'metric_date' => 'date',
            'extra' => 'array',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }
}
