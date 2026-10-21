<?php

namespace App\Domain\AI\Data;

/** Génération d'une image à partir d'un prompt (ADR-046). */
final readonly class AiImageRequest
{
    public function __construct(
        public string $model,
        public string $prompt,
        /** Taille demandée au fournisseur, « LARGEURxHAUTEUR » (config ai.images.sizes). */
        public string $size,
        /** Format final (1:1, 4:5, 9:16, 16:9), produit nativement par les fournisseurs qui le permettent. */
        public ?string $aspectRatio = null,
    ) {}
}
