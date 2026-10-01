<?php

namespace App\Domain\Publishing\Data;

use App\Domain\Publishing\Enums\PublishOutcome;

/**
 * Résultat normalisé d'un adaptateur. Les métadonnées ne doivent contenir aucune donnée
 * sensible (elles sont de toute façon expurgées avant enregistrement).
 */
final readonly class PublishResult
{
    /** @param array<string, mixed> $metadata */
    private function __construct(
        public PublishOutcome $outcome,
        public ?string $code,
        public ?string $message,
        public bool $retryable,
        public ?string $externalPostId = null,
        public ?string $externalUrl = null,
        public ?int $httpStatus = null,
        public array $metadata = [],
    ) {}

    /** @param array<string, mixed> $metadata */
    public static function success(string $externalPostId, ?string $externalUrl = null, array $metadata = []): self
    {
        return new self(PublishOutcome::Success, null, null, false, $externalPostId, $externalUrl, null, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function temporaryFailure(string $code, string $message, ?int $httpStatus = null, array $metadata = []): self
    {
        return new self(PublishOutcome::TemporaryFailure, $code, $message, true, null, null, $httpStatus, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function permanentFailure(string $code, string $message, ?int $httpStatus = null, array $metadata = []): self
    {
        return new self(PublishOutcome::PermanentFailure, $code, $message, false, null, null, $httpStatus, $metadata);
    }

    public static function notConfigured(string $message): self
    {
        return new self(PublishOutcome::NotConfigured, 'NOT_CONFIGURED', $message, false);
    }

    /**
     * Contenu transmis, en cours de traitement chez le réseau. $trackingId (ex. publish_id TikTok)
     * est enregistré dans publication_attempts.external_post_id et sert au suivi.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function processing(string $trackingId, array $metadata = []): self
    {
        return new self(PublishOutcome::Processing, null, null, false, $trackingId, null, null, $metadata);
    }

    /** @param array<string, mixed> $metadata */
    public static function unknown(string $code, string $message, array $metadata = []): self
    {
        return new self(PublishOutcome::Unknown, $code, $message, false, null, null, null, $metadata);
    }
}
