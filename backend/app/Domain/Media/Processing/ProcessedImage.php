<?php

namespace App\Domain\Media\Processing;

/** Résultat du traitement d'une image : original (réencodé si possible) et vignette. */
final class ProcessedImage
{
    public function __construct(
        public readonly string $original,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $reencoded,
        public readonly ?string $thumbnail = null,
        public readonly ?string $thumbnailExtension = null,
        public readonly ?string $thumbnailMime = null,
    ) {}
}
