<?php

namespace App\Domain\AI\Exceptions;

use RuntimeException;

/**
 * Erreur normalisée d'un fournisseur IA (ADR-043). Le message est destiné à l'utilisateur ou à
 * l'opérateur : il ne contient jamais de clé API, d'en-tête Authorization, ni le message brut du
 * fournisseur (qui peut citer une partie de la clé) — seulement un code et, pour diagnostic,
 * le type/code d'erreur renvoyé par le fournisseur.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        /** Vrai si l'erreur est passagère (réseau, délai, 429, 5xx) : une nouvelle tentative a un sens. */
        public readonly bool $transient = false,
        public readonly ?int $httpStatus = null,
        /** Code d'erreur du fournisseur (ex. invalid_api_key, insufficient_quota), jamais son message. */
        public readonly ?string $providerCode = null,
    ) {
        parent::__construct($message);
    }

    public static function notConfigured(string $detail): self
    {
        return new self('NOT_CONFIGURED', $detail);
    }
}
