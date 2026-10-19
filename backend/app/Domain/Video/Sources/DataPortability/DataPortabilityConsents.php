<?php

namespace App\Domain\Video\Sources\DataPortability;

use App\Domain\Social\Enums\TokenKind;
use App\Domain\Social\Enums\TokenStatus;
use App\Domain\Social\OAuth\OAuthException;
use App\Domain\Social\OAuth\OAuthTokens;
use App\Models\SocialAccount;
use App\Models\SocialToken;
use App\Models\User;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Consentements Data Portability (ADR-054) : jeton chiffré (SocialToken, token_kind « portability »)
 * rattaché au compte YouTube PROPRIÉTAIRE de la vidéo, distinct du jeton de publication. Un seul
 * jeton actif par compte ; renouvelé sous verrou s'il expire (accès de 30 ou 180 jours) ; refusé
 * par Google → marqué invalide, nouveau consentement demandé. Jamais journalisé ni exposé.
 */
class DataPortabilityConsents
{
    private const REFRESH_MARGIN_SECONDS = 300;

    public function __construct(private readonly DataPortabilityOAuth $oauth) {}

    /** Enregistre l'autorisation accordée pour ce compte YouTube (remplace la précédente). */
    public function store(SocialAccount $account, OAuthTokens $tokens): SocialToken
    {
        return DB::transaction(function () use ($account, $tokens) {
            SocialToken::query()->where('social_account_id', $account->getKey())
                ->where('token_kind', TokenKind::Portability->value)
                ->where('status', TokenStatus::Active->value)
                ->lockForUpdate()->get()
                ->each(fn (SocialToken $old) => $old->forceFill(['status' => TokenStatus::Revoked])->save());

            $token = new SocialToken([
                'access_token_encrypted' => $tokens->accessToken,
                'refresh_token_encrypted' => $tokens->refreshToken,
            ]);
            $token->forceFill([
                'social_account_id' => $account->getKey(),
                'token_kind' => TokenKind::Portability,
                'status' => TokenStatus::Active,
                'expires_at' => $tokens->expiresAt,
                'scopes' => $tokens->scopes,
                'token_type' => $tokens->tokenType,
                'last_refreshed_at' => now(),
            ])->save();

            return $token;
        });
    }

    /**
     * Groupes de ressources accordés par le consentement actif du compte YouTube propriétaire.
     *
     * @return list<string>
     */
    public function grantedResources(User $user, ?string $accountId = null): array
    {
        $account = $this->account($user, $accountId);

        return $account !== null ? $this->oauth->grantedResources((array) ($this->active($account)?->scopes ?? [])) : [];
    }

    /**
     * Jeton d'accès valide pour le compte YouTube propriétaire ($accountId) de cet utilisateur, ou null
     * (aucun consentement, consentement retiré ou expiré : un nouveau consentement est nécessaire).
     */
    public function accessTokenFor(User $user, ?string $accountId = null): ?string
    {
        $account = $this->account($user, $accountId);
        $token = $account !== null ? $this->active($account) : null;
        if ($token === null) {
            return null;
        }
        if (! $this->expiresSoon($token)) {
            return $token->access_token_encrypted;
        }
        if ($token->refresh_token_encrypted === null) {
            $token->forceFill(['status' => TokenStatus::Expired])->save(); // accès ponctuel terminé

            return null;
        }
        try {
            return Cache::lock("portability-token-refresh:{$account->getKey()}", 30)->block(15, function () use ($account) {
                $token = $this->active($account);
                if ($token === null) {
                    return null;
                }
                if (! $this->expiresSoon($token)) {
                    return $token->access_token_encrypted; // renouvelé entre-temps par un autre worker
                }
                try {
                    $fresh = $this->oauth->refresh((string) $token->refresh_token_encrypted);
                } catch (OAuthException $e) {
                    if ($e->requiresReconnect) {
                        $token->forceFill(['status' => TokenStatus::Invalid])->save();

                        return null;
                    }
                    throw $e;
                }
                $token->access_token_encrypted = $fresh->accessToken;
                if ($fresh->refreshToken !== null) {
                    $token->refresh_token_encrypted = $fresh->refreshToken;
                }
                $token->forceFill(['expires_at' => $fresh->expiresAt, 'last_refreshed_at' => now()])->save();

                return $fresh->accessToken;
            });
        } catch (LockTimeoutException) {
            throw new OAuthException('TOKEN_REFRESH_BUSY', 'Renouvellement de l\'autorisation Google en cours.', transient: true);
        }
    }

    /** Autorisation devenue inutilisable (accès « une seule fois » consommé) : un nouveau consentement sera demandé. */
    public function expire(User $user, ?string $accountId = null): void
    {
        $account = $this->account($user, $accountId);
        $token = $account !== null ? $this->active($account) : null;
        $token?->forceFill(['status' => TokenStatus::Expired])->save();
    }

    /** Compte YouTube connecté de l'utilisateur (celui indiqué, sinon le seul qui porte un consentement). */
    private function account(User $user, ?string $accountId): ?SocialAccount
    {
        $accounts = SocialAccount::query()->where('user_id', $user->getKey())
            ->whereHas('platform', fn ($q) => $q->where('key', 'youtube'))
            ->when($accountId !== null, fn ($q) => $q->whereKey($accountId))
            ->get()->filter(fn (SocialAccount $a) => $a->isConnected() && ! $a->isDemo());

        return $accounts->first(fn (SocialAccount $a) => $this->active($a) !== null);
    }

    private function active(SocialAccount $account): ?SocialToken
    {
        return SocialToken::query()->where('social_account_id', $account->getKey())
            ->where('token_kind', TokenKind::Portability->value)
            ->where('status', TokenStatus::Active->value)
            ->first();
    }

    private function expiresSoon(SocialToken $token): bool
    {
        return $token->expires_at === null || $token->expires_at->lte(now()->addSeconds(self::REFRESH_MARGIN_SECONDS));
    }
}
