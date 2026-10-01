<?php

namespace App\Domain\Publishing\Data;

/**
 * Ce qu'un adaptateur reçoit pour publier une variante. Aucun token ni secret : un futur
 * adaptateur les obtiendra lui-même, déchiffrés au dernier moment (docs/security.md).
 */
final readonly class PublishRequest
{
    /**
     * @param  array<string, mixed>  $options  options de la variante (validées par le Composer)
     * @param  list<array{id: string, type: string, mime_type: ?string}>  $media  médias utilisables, dans l'ordre
     */
    public function __construct(
        public string $postId,
        public string $variantId,
        public string $socialAccountId,
        public ?string $platform,
        public string $externalAccountId,
        public string $format,
        public string $body,
        public array $options,
        public array $media,
        public string $idempotencyKey,
        public int $attemptNumber,
        /** Titre de la publication (null si absent) : titre de vidéo YouTube par défaut. */
        public ?string $title = null,
    ) {}
}
