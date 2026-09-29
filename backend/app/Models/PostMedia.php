<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Lien publication ↔ média (post_variant_id NULL = commun à toutes les variantes). */
class PostMedia extends Pivot
{
    use HasUuids;

    protected $table = 'post_media';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['post_id', 'post_variant_id', 'media_id', 'position', 'alt_text', 'crop'];

    protected function casts(): array
    {
        return ['crop' => 'array', 'position' => 'integer'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
