<?php

namespace App\Models;

use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\Security\EncryptedToken;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Token OAuth — donnée CRITIQUE (docs/security.md §5).
 * Chiffré au repos, jamais sérialisé, jamais exposé par une ressource d'API ni journalisé.
 */
class SocialToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'social_account_id',
        'token_kind',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'expires_at',
        'refresh_expires_at',
        'scopes',
        'token_type',
        'status',
    ];

    /** Aucune colonne de token dans toArray()/toJson(). */
    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted'];

    protected function casts(): array
    {
        return [
            'access_token_encrypted' => EncryptedToken::class,
            'refresh_token_encrypted' => EncryptedToken::class,
            'token_kind' => TokenKind::class,
            'status' => TokenStatus::class,
            'scopes' => 'array',
            'expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_refreshed_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class, 'social_account_id');
    }

    /** Évite toute fuite accidentelle par conversion en chaîne ou var_dump. */
    public function __toString(): string
    {
        return 'SocialToken['.$this->getKey().']';
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->getKey(), 'social_account_id' => $this->social_account_id, 'status' => $this->status];
    }
}
