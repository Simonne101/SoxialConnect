<?php

namespace App\Domain\AI\Data;

/** Image générée : octets décodés (PNG, JPEG ou WebP) et usage déclaré par le fournisseur. */
final readonly class AiImageResult
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $bytes,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
    ) {}
}
