<?php

namespace App\Domain\Social\OAuth;

use Carbon\CarbonImmutable;

/**
 * Tokens renvoyés par un fournisseur OAuth. N'existe qu'en mémoire, le temps de les chiffrer
 * (SocialToken) : jamais sérialisé, journalisé ni renvoyé au frontend.
 */
final readonly class OAuthTokens
{
    /** @param list<string> $scopes */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?CarbonImmutable $expiresAt,
        public array $scopes,
        public ?string $tokenType = 'Bearer',
        /** Identifiant du compte chez le fournisseur quand il est renvoyé avec le token (TikTok : open_id). */
        public ?string $providerUserId = null,
        /** Expiration du refresh token quand le fournisseur la communique (TikTok : 365 jours). */
        public ?CarbonImmutable $refreshExpiresAt = null,
    ) {}

    /** Évite toute fuite par var_dump / dump / journal d'exception. */
    public function __debugInfo(): array
    {
        return [
            'expiresAt' => $this->expiresAt?->toIso8601String(),
            'refreshExpiresAt' => $this->refreshExpiresAt?->toIso8601String(),
            'scopes' => $this->scopes,
            'hasRefreshToken' => $this->refreshToken !== null,
        ];
    }
}
