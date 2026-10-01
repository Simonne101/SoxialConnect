<?php

namespace App\Domain\Social\Tokens;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\TikTokOAuthProvider;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Jeton d'accès TikTok valide pour un compte (docs/security.md §5, docs/publishing.md §12) :
 * - access token valable 24 h : réutilisé tant qu'il n'expire pas dans les 10 minutes ;
 * - sinon rafraîchi sous verrou par compte ; TikTok peut renvoyer un NOUVEAU refresh token
 *   (rotation) : access ET refresh token sont alors tous deux rechiffrés, l'ancien refresh token
 *   n'est jamais conservé ;
 * - refresh token expiré (365 jours) ou refusé : aucune boucle, compte « à reconnecter ».
 * Même modèle que YouTubeTokenManager, sans le modifier.
 */
final class TikTokTokenManager
{
    private const REFRESH_MARGIN_SECONDS = 600;

    public function __construct(
        private readonly TikTokOAuthProvider $oauth,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws OAuthException */
    public function accessTokenFor(SocialAccount $account, bool $forceRefresh = false): string
    {
        if (! $account->isConnected()) {
            throw $this->oauth->reconnect('ACCOUNT_DISCONNECTED');
        }
        $token = $this->activeToken($account);
        if ($token === null) {
            $this->markReconnectRequired($account, null, 'TOKEN_MISSING');
            throw $this->oauth->reconnect('TOKEN_MISSING');
        }
        if (! $forceRefresh && ! $this->expiresSoon($token)) {
            return $token->access_token_encrypted;
        }

        $seen = $token->last_refreshed_at?->getTimestamp();
        try {
            return Cache::lock("social-token-refresh:{$account->getKey()}", 30)->block(15, function () use ($account, $forceRefresh, $seen) {
                // Relu sous verrou : un autre worker a peut-être déjà rafraîchi (et fait tourner le refresh token).
                $token = $this->activeToken($account);
                if ($token === null) {
                    throw $this->oauth->reconnect('TOKEN_MISSING');
                }
                $refreshedMeanwhile = $token->last_refreshed_at?->getTimestamp() !== $seen;
                if (($forceRefresh && $refreshedMeanwhile) || (! $forceRefresh && ! $this->expiresSoon($token))) {
                    return $token->access_token_encrypted;
                }

                return $this->refresh($account, $token);
            });
        } catch (LockTimeoutException) {
            throw new OAuthException('TOKEN_REFRESH_BUSY', 'Le renouvellement de l\'autorisation TikTok est en cours. Réessayez dans un instant.', transient: true);
        }
    }

    public function activeToken(SocialAccount $account): ?SocialToken
    {
        return SocialToken::query()
            ->where('social_account_id', $account->getKey())
            ->where('token_kind', TokenKind::User->value)
            ->where('status', TokenStatus::Active->value)
            ->first();
    }

    private function refresh(SocialAccount $account, SocialToken $token): string
    {
        $refreshToken = $token->refresh_token_encrypted;
        if ($refreshToken === null) {
            $this->markReconnectRequired($account, $token, 'REFRESH_TOKEN_MISSING');
            throw $this->oauth->reconnect('REFRESH_TOKEN_MISSING');
        }
        if ($token->refresh_expires_at !== null && $token->refresh_expires_at->isPast()) {
            $this->markReconnectRequired($account, $token, 'REFRESH_TOKEN_EXPIRED');
            throw $this->oauth->reconnect('REFRESH_TOKEN_EXPIRED');
        }

        try {
            $fresh = $this->oauth->refresh($refreshToken);
        } catch (OAuthException $e) {
            if ($e->requiresReconnect) {
                $this->markReconnectRequired($account, $token, $e->errorCode);
            }
            throw $e;
        }

        $token->access_token_encrypted = $fresh->accessToken;
        if ($fresh->refreshToken !== null) {
            // Rotation : le nouveau refresh token remplace l'ancien (qui n'est plus valable).
            $token->refresh_token_encrypted = $fresh->refreshToken;
        }
        $token->expires_at = $fresh->expiresAt;
        if ($fresh->refreshExpiresAt !== null) {
            $token->refresh_expires_at = $fresh->refreshExpiresAt;
        }
        if ($fresh->scopes !== []) {
            $token->scopes = $fresh->scopes;
        }
        $token->forceFill(['last_refreshed_at' => now()])->save();
        $this->audit->record(AuditAction::TokenRefreshed, null, $account, ['platform' => 'tiktok'], 'system');

        return $fresh->accessToken;
    }

    private function expiresSoon(SocialToken $token): bool
    {
        return $token->expires_at === null || $token->expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }

    /** Autorisation définitivement invalide : plus aucun appel tant que l'utilisateur ne s'est pas reconnecté. */
    private function markReconnectRequired(SocialAccount $account, ?SocialToken $token, string $code): void
    {
        $token?->forceFill(['status' => TokenStatus::Invalid])->save();
        $account->forceFill([
            'status' => SocialAccountStatus::Expired,
            'status_reason' => 'Autorisation TikTok expirée ou retirée : reconnectez le compte.',
            'last_error_at' => now(),
        ])->save();
        $this->audit->record(AuditAction::TokenRefreshFailed, null, $account, ['platform' => 'tiktok', 'error_code' => $code], 'system');
    }
}
