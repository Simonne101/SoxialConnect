<?php

namespace App\Models;

use App\Services\Audit\AuditAction;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Journal d'audit en ajout seul (docs/database.md §3.8) : écrit exclusivement par
 * App\Services\Audit\AuditLogger, jamais modifié ni supprimé par l'application.
 */
class AuditLog extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'actor_type', 'action', 'subject_type', 'subject_id',
        'ip_address', 'user_agent', 'request_id', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal d\'audit est en ajout seul.'));
        static::deleting(fn () => throw new LogicException('Le journal d\'audit est en ajout seul.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
