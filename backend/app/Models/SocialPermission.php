<?php

namespace App\Models;

use App\Domain\Social\Enums\PermissionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialPermission extends Model
{
    use HasUuids;

    protected $fillable = ['social_account_id', 'scope', 'status', 'is_required', 'checked_at'];

    protected function casts(): array
    {
        return [
            'status' => PermissionStatus::class,
            'is_required' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }
}
