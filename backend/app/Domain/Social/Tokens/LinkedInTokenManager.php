<?php

namespace App\Domain\Social\Tokens;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\LinkedInOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;

/**
 * Jeton d'accès LinkedIn valide pour un compte (docs/publishing.md §14) :
 * - access token valable 60 jours, utilisé tel quel tant qu'il n'est pas expiré ;
 * - aucun refresh programmatique (réservé aux partenaires LinkedIn) : à expiration, ou si LinkedIn
 *   le refuse, le compte passe « à reconnecter » et plus aucun appel n'est tenté.
 * Même modèle que les gestionnaires YouTube et TikTok, sans les modifier.
 */
final class LinkedInTokenManager
{
    /** Marge de sécurité : un jeton qui expire dans la minute n'est plus utilisé. */
    private const EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private readonly LinkedInOAuthProvider $oauth,
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
        if ($token->expires_at !== null && $token->expires_at->lte(now()->addSeconds(self::EXPIRY_MARGIN_SECONDS))) {
            $this->markReconnectRequired($account, $token, 'TOKEN_EXPIRED');
            throw $this->oauth->reconnect('TOKEN_EXPIRED');
        }

        return $token->access_token_encrypted;
    }

    public function activeToken(SocialAccount $account): ?SocialToken
    {
        return SocialToken::query()
            ->where('social_account_id', $account->getKey())
            ->where('token_kind', TokenKind::User->value)
            ->where('status', TokenStatus::Active->value)
            ->first();
    }

    /** Jeton refusé par LinkedIn (401) : plus aucun appel tant que l'utilisateur ne s'est pas reconnecté. */
    public function rejected(SocialAccount $account): void
    {
        $this->markReconnectRequired($account, $this->activeToken($account), 'TOKEN_INVALID');
    }

    private function markReconnectRequired(SocialAccount $account, ?SocialToken $token, string $code): void
    {
        $token?->forceFill(['status' => $code === 'TOKEN_EXPIRED' ? TokenStatus::Expired : TokenStatus::Invalid])->save();
        $account->forceFill([
            'status' => SocialAccountStatus::Expired,
            'status_reason' => 'Autorisation LinkedIn expirée ou retirée : reconnectez le compte.',
            'last_error_at' => now(),
        ])->save();
        $this->audit->record(AuditAction::TokenRefreshFailed, null, $account, ['platform' => 'linkedin', 'error_code' => $code], 'system');
    }
}
