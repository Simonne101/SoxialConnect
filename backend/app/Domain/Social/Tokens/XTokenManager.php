<?php

namespace App\Domain\Social\Tokens;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\XOAuthProvider;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Jeton d'accès X valide pour un compte (docs/publishing.md §15) :
 * - access token valable 2 h : réutilisé tant qu'il n'expire pas dans les 10 minutes ;
 * - sinon rafraîchi sous verrou par compte. Le refresh token X est à usage unique : X en renvoie
 *   un nouveau à chaque renouvellement, enregistré (chiffré) dans la même écriture que le nouvel
 *   access token ; l'ancien n'est jamais conservé ;
 * - refresh token absent ou refusé : aucune boucle, compte « à reconnecter ».
 * Même modèle que TikTokTokenManager, sans le modifier. Point d'entrée de la future publication X.
 */
final class XTokenManager
{
    private const REFRESH_MARGIN_SECONDS = 600;

    public function __construct(
        private readonly XOAuthProvider $oauth,
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
                // Relu sous verrou : un autre worker a peut-être déjà rafraîchi (et consommé le refresh token).
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
            throw new OAuthException('TOKEN_REFRESH_BUSY', 'Le renouvellement de l\'autorisation X est en cours. Réessayez dans un instant.', transient: true);
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

    /** Jeton refusé par X (401) : plus aucun appel tant que l'utilisateur ne s'est pas reconnecté. */
    public function rejected(SocialAccount $account): void
    {
        $this->markReconnectRequired($account, $this->activeToken($account), 'TOKEN_INVALID');
    }

    private function refresh(SocialAccount $account, SocialToken $token): string
    {
        $refreshToken = $token->refresh_token_encrypted;
        if ($refreshToken === null) {
            $this->markReconnectRequired($account, $token, 'REFRESH_TOKEN_MISSING');
            throw $this->oauth->reconnect('REFRESH_TOKEN_MISSING');
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
            // Rotation : le nouveau refresh token remplace l'ancien (déjà consommé par X).
            $token->refresh_token_encrypted = $fresh->refreshToken;
        }
        $token->expires_at = $fresh->expiresAt;
        if ($fresh->scopes !== []) {
            $token->scopes = $fresh->scopes;
        }
        $token->forceFill(['last_refreshed_at' => now()])->save();
        $this->audit->record(AuditAction::TokenRefreshed, null, $account, ['platform' => 'x'], 'system');

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
            'status_reason' => 'Autorisation X expirée ou retirée : reconnectez le compte.',
            'last_error_at' => now(),
        ])->save();
        $this->audit->record(AuditAction::TokenRefreshFailed, null, $account, ['platform' => 'x', 'error_code' => $code], 'system');
    }
}
