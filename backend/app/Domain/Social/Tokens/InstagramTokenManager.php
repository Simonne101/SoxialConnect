<?php

namespace App\Domain\Social\Tokens;

use App\Domain\Social\Enums\SocialAccountStatus;
use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\InstagramOAuthProvider;
use App\Domain\Social\OAuth\OAuthException;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Services\Audit\AuditAction;
use App\Services\Audit\AuditLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

/**
 * Jeton Instagram valide pour un compte (docs/publishing.md §17) :
 * - jeton longue durée (60 jours) réutilisé tel quel ;
 * - renouvelé sous verrou par compte quand il expire dans moins de 10 jours, s'il a au moins 24 h
 *   (exigence d'Instagram) : le jeton renvoyé remplace l'ancien dans la même écriture ;
 * - expiré, ou refusé par Instagram : compte « à reconnecter », plus aucun appel.
 * Même modèle que XTokenManager (renouvellement sous verrou), sans le modifier.
 */
final class InstagramTokenManager
{
    private const REFRESH_WITHIN_DAYS = 10;

    private const MIN_AGE_HOURS = 24;

    public function __construct(
        private readonly InstagramOAuthProvider $oauth,
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
        if ($token->expires_at !== null && $token->expires_at->lte(now()->addMinute())) {
            $this->markReconnectRequired($account, $token, 'TOKEN_EXPIRED');
            throw $this->oauth->reconnect('TOKEN_EXPIRED');
        }
        if (! $this->shouldRefresh($token)) {
            return $token->access_token_encrypted;
        }

        try {
            return Cache::lock("social-token-refresh:{$account->getKey()}", 30)->block(15, function () use ($account) {
                // Relu sous verrou : un autre worker a peut-être déjà renouvelé le jeton.
                $token = $this->activeToken($account);
                if ($token === null) {
                    throw $this->oauth->reconnect('TOKEN_MISSING');
                }

                return $this->shouldRefresh($token) ? $this->refresh($account, $token) : $token->access_token_encrypted;
            });
        } catch (LockTimeoutException) {
            // Jeton encore valide : utilisé tel quel, le renouvellement sera retenté au prochain appel.
            return $token->access_token_encrypted;
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

    /** Jeton refusé par Instagram : plus aucun appel tant que l'utilisateur ne s'est pas reconnecté. */
    public function rejected(SocialAccount $account): void
    {
        $this->markReconnectRequired($account, $this->activeToken($account), 'TOKEN_INVALID');
    }

    private function shouldRefresh(SocialToken $token): bool
    {
        $issuedAt = $token->last_refreshed_at ?? $token->created_at;

        return $token->expires_at !== null
            && $token->expires_at->lte(now()->addDays(self::REFRESH_WITHIN_DAYS))
            && ($issuedAt === null || $issuedAt->lte(now()->subHours(self::MIN_AGE_HOURS)));
    }

    private function refresh(SocialAccount $account, SocialToken $token): string
    {
        try {
            $fresh = $this->oauth->refresh($token->access_token_encrypted);
        } catch (OAuthException $e) {
            if ($e->requiresReconnect) {
                $this->markReconnectRequired($account, $token, $e->errorCode);
                throw $e;
            }

            // Échec passager : le jeton actuel reste valide quelques jours, il est utilisé tel quel.
            return $token->access_token_encrypted;
        }

        $token->access_token_encrypted = $fresh->accessToken;
        $token->expires_at = $fresh->expiresAt;
        $token->forceFill(['last_refreshed_at' => now()])->save();
        $this->audit->record(AuditAction::TokenRefreshed, null, $account, ['platform' => 'instagram'], 'system');

        return $fresh->accessToken;
    }

    private function markReconnectRequired(SocialAccount $account, ?SocialToken $token, string $code): void
    {
        $token?->forceFill(['status' => $code === 'TOKEN_EXPIRED' ? TokenStatus::Expired : TokenStatus::Invalid])->save();
        $account->forceFill([
            'status' => SocialAccountStatus::Expired,
            'status_reason' => 'Autorisation Instagram expirée ou retirée : reconnectez le compte.',
            'last_error_at' => now(),
        ])->save();
        $this->audit->record(AuditAction::TokenRefreshFailed, null, $account, ['platform' => 'instagram', 'error_code' => $code], 'system');
    }
}
