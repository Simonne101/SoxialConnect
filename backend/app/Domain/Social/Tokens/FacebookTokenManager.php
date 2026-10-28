<?php

namespace App\Domain\Social\Tokens;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\FacebookOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;

/**
 * Jeton de Page Facebook valide pour un compte (docs/publishing.md §16) :
 * - jeton de Page issu d'un jeton utilisateur longue durée : pas d'expiration, aucun renouvellement ;
 * - refusé par Facebook (code 190 : accès retiré, mot de passe changé, rôle perdu) : le compte passe
 *   « à reconnecter » et plus aucun appel n'est tenté.
 * Même modèle que LinkedInTokenManager, sans le modifier.
 */
final class FacebookTokenManager
{
    public function __construct(
        private readonly FacebookOAuthProvider $oauth,
        private readonly AuditLogger $audit,
    ) {}

    /** @throws OAuthException */
    public function accessTokenFor(SocialAccount $account): string
    {
        if (! $account->isConnected()) {
            throw $this->oauth->reconnect('ACCOUNT_DISCONNECTED');
        }
        $token = $this->activeToken($account);
        if ($token === null) {
            $this->markReconnectRequired($account, null, 'TOKEN_MISSING');
            throw $this->oauth->reconnect('TOKEN_MISSING');
        }
        if ($token->expires_at !== null && $token->expires_at->lte(now())) {
            $this->markReconnectRequired($account, $token, 'TOKEN_EXPIRED');
            throw $this->oauth->reconnect('TOKEN_EXPIRED');
        }

        return $token->access_token_encrypted;
    }

    public function activeToken(SocialAccount $account): ?SocialToken
    {
        return SocialToken::query()
            ->where('social_account_id', $account->getKey())
            ->where('token_kind', TokenKind::Page->value)
            ->where('status', TokenStatus::Active->value)
            ->first();
    }

    /** Jeton refusé par Facebook : plus aucun appel tant que l'utilisateur ne s'est pas reconnecté. */
    public function rejected(SocialAccount $account): void
    {
        $this->markReconnectRequired($account, $this->activeToken($account), 'TOKEN_INVALID');
    }

    private function markReconnectRequired(SocialAccount $account, ?SocialToken $token, string $code): void
    {
        $token?->forceFill(['status' => $code === 'TOKEN_EXPIRED' ? TokenStatus::Expired : TokenStatus::Invalid])->save();
        $account->forceFill([
            'status' => SocialAccountStatus::Expired,
            'status_reason' => 'Autorisation Facebook expirée ou retirée : reconnectez la Page.',
            'last_error_at' => now(),
        ])->save();
        $this->audit->record(AuditAction::TokenRefreshFailed, null, $account, ['platform' => 'facebook', 'error_code' => $code], 'system');
    }
}
