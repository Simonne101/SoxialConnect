<?php

namespace App\Domain\Video\Rendering;

/** MP4 produit et validé par FFprobe, encore dans le dossier de travail du rendu. */
final readonly class RenderedVideo
{
    public function __construct(
        public string $path,
        public ?string $thumbnailPath,
        public ProbeResult $probe,
        public int $sizeBytes,
    ) {}
}
