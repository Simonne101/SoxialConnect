<?php

namespace App\Domain\Social\Telegram;

use RuntimeException;

/**
 * Erreur de la Bot API Telegram, déjà classée. Ne contient JAMAIS le token du bot : l'URL d'appel
 * (qui le contient) n'est ni conservée ni chaînée (aucune exception « previous »).
 *
 * - $errorCode : error_code Telegram (400, 403, 429…) ou 0 pour une erreur réseau ;
 * - $transient : un nouvel essai a un sens (limite de débit, indisponibilité, réseau) ;
 * - $maybeSent : la requête a pu être traitée par Telegram (délai dépassé pendant l'envoi,
 *   erreur serveur) → pour un envoi de message, résultat inconnu : jamais de relance aveugle.
 */
final class TelegramApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $errorCode,
        public readonly string $description,
        public readonly bool $transient = false,
        public readonly bool $maybeSent = false,
        public readonly ?int $retryAfter = null,
        public readonly ?int $migrateToChatId = null,
        /** Statut HTTP de la réponse (null : erreur réseau). */
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    /** @param array<string, mixed> $body réponse JSON de la Bot API ({ ok: false, error_code, description, parameters }) */
    public static function fromResponse(int $httpStatus, array $body): self
    {
        $code = (int) ($body['error_code'] ?? $httpStatus);
        $description = mb_substr(trim((string) ($body['description'] ?? '')), 0, 300);
        $retryAfter = isset($body['parameters']['retry_after']) ? (int) $body['parameters']['retry_after'] : null;
        $migrate = isset($body['parameters']['migrate_to_chat_id']) ? (int) $body['parameters']['migrate_to_chat_id'] : null;

        return new self(
            'Telegram a refusé la requête ('.$code.($description !== '' ? ' : '.$description : '').').',
            $code,
            $description,
            transient: $code === 429 || $code >= 500,
            maybeSent: $code >= 500,
            retryAfter: $retryAfter,
            migrateToChatId: $migrate,
            httpStatus: $httpStatus,
        );
    }

    public static function network(bool $maybeSent): self
    {
        return new self(
            $maybeSent ? 'Telegram n\'a pas répondu à temps.' : 'Telegram est momentanément injoignable.',
            0,
            '',
            transient: true,
            maybeSent: $maybeSent,
        );
    }

    public static function invalidResponse(): self
    {
        return new self('Réponse inattendue de Telegram.', 0, '', transient: true, maybeSent: true);
    }

    public function descriptionContains(string ...$needles): bool
    {
        $haystack = mb_strtolower($this->description);
        foreach ($needles as $needle) {
            if (str_contains($haystack, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
