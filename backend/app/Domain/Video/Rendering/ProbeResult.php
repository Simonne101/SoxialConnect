<?php

namespace App\Domain\Video\Rendering;

/** Caractéristiques d'un fichier lues par FFprobe (durées en millisecondes). */
final readonly class ProbeResult
{
    public function __construct(
        public string $formatName,
        public ?int $durationMs,
        public bool $hasVideo,
        public bool $hasAudio,
        public ?string $videoCodec,
        public ?string $audioCodec,
        public ?int $width,
        public ?int $height,
        public ?float $fps,
    ) {}
}
