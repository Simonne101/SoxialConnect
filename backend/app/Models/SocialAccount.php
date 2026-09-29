<?php

namespace App\Models;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\SocialAccountType;
use App\Models\Concerns\BelongsToUser;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SocialAccount extends Model
{
    use BelongsToUser, HasFactory, HasUuids, SoftDeletes;

    /** user_id et le statut sont fixés par le domaine (connexion OAuth), jamais par le client. */
    protected $fillable = [
        'social_platform_id',
        'external_account_id',
        'account_type',
        'display_name',
        'username',
        'avatar_media_path',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => SocialAccountStatus::class,
            'account_type' => SocialAccountType::class,
            'metadata' => 'array',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }

    public function isConnected(): bool
    {
        return $this->status === SocialAccountStatus::Connected;
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(SocialPlatform::class, 'social_platform_id');
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(SocialToken::class);
    }

    public function permissions(): HasMany
    {
        return $this->hasMany(SocialPermission::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(PostVariant::class);
    }
}
