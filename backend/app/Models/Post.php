<?php

namespace App\Models;

use App\Domain\Content\Enums\PostSource;
use App\Domain\Content\Enums\PostStatus;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Publication « maître » (docs/database.md §3.3). Son statut est agrégé à partir de ses
 * variantes par App\Domain\Publishing\PostStatusAggregator — jamais saisi par le client.
 */
class Post extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = ['title', 'body', 'scheduled_at', 'timezone'];

    protected $attributes = [
        'status' => 'draft',
        'source' => 'manual',
        'lock_version' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => PostStatus::class,
            'source' => PostSource::class,
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'lock_version' => 'integer',
        ];
    }

    /** Publications modifiables (docs/ux-ui.md : les publiées ne se modifient plus). */
    public function isEditable(): bool
    {
        return in_array($this->status, [PostStatus::Draft, PostStatus::Scheduled, PostStatus::Failed, PostStatus::Cancelled], true);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }

    /** Médias communs à toutes les variantes (post_variant_id NULL), ordonnés. */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'post_media')
            ->using(PostMedia::class)
            ->withPivot(['id', 'post_variant_id', 'position', 'alt_text'])
            ->wherePivotNull('post_variant_id')
            ->orderByPivot('position');
    }

    public function postMedia(): HasMany
    {
        return $this->hasMany(PostMedia::class);
    }
}
