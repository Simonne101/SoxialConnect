<?php

namespace App\Models;

use App\Domain\Content\Enums\PostFormat;
use App\Domain\Content\Enums\VariantStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Déclinaison d'une publication pour un compte social : l'unité de publication. */
class PostVariant extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['social_account_id', 'body', 'format', 'options', 'scheduled_at'];

    protected $attributes = ['status' => 'draft', 'attempts_count' => 0];

    protected function casts(): array
    {
        return [
            'format' => PostFormat::class,
            'status' => VariantStatus::class,
            'options' => 'array',
            'scheduled_at' => 'datetime',
            'queued_at' => 'datetime',
            'attempts_count' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PublicationAttempt::class);
    }

    /** Dernière tentative (numéro le plus élevé). */
    public function latestAttempt(): HasOne
    {
        return $this->hasOne(PublicationAttempt::class)->ofMany('attempt_number', 'max');
    }
}
