<?php

namespace App\Models;

use App\Domain\Analytics\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * État de la collecte d'un compte (post_variant_id null) ou d'une publication. Une collecte échouée garde ici
 * son code et sa date de tentative ; les dernières valeurs valides restent dans `analytics` (jamais écrasées
 * par 0 ou NULL à cause d'une erreur).
 */
class AnalyticsSyncState extends Model
{
    use HasUuids;

    protected $fillable = [
        'social_account_id', 'post_variant_id', 'status', 'last_attempt_at', 'last_success_at',
        'last_error_code', 'last_error_message', 'consecutive_failures', 'next_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SyncStatus::class,
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'consecutive_failures' => 'integer',
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
