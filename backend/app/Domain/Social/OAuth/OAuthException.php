<?php

namespace App\Domain\Social\OAuth;

use RuntimeException;

/**
 * Erreur OAuth normalisée. Le message est destiné à l'utilisateur ; il ne contient jamais de
 * token, de code d'autorisation ni de secret (seulement le code d'erreur renvoyé par le fournisseur).
 */
final class OAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        /** Vrai si l'autorisation est définitivement invalide (refresh refusé, accès révoqué). */
        public readonly bool $requiresReconnect = false,
        /** Vrai si l'erreur est passagère (réseau, 5xx) : une nouvelle tentative a un sens. */
        public readonly bool $transient = false,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(): self
    {
        return new self('NOT_CONFIGURED', 'La connexion YouTube n\'est pas configurée sur ce serveur (identifiants Google absents).');
    }

    public static function network(): self
    {
        return new self('NETWORK_ERROR', 'Google est momentanément injoignable. Réessayez dans un instant.', transient: true);
    }

    public static function reconnectRequired(string $code = 'TOKEN_REVOKED'): self
    {
        return new self($code, 'L\'autorisation YouTube a expiré ou a été retirée. Reconnectez le compte puis réessayez.', requiresReconnect: true);
    }
}
