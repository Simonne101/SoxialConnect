<?php

namespace App\Domain\AI\Data;

/**
 * Transcription normalisée : texte complet et segments horodatés en millisecondes. Ne contient ni
 * clé, ni réponse brute du fournisseur.
 */
final readonly class AiTranscriptionResult
{
    /**
     * @param  list<array{start_ms: int, end_ms: int, text: string}>  $segments
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $text,
        public ?string $language,
        public array $segments,
        public int $latencyMs,
    ) {}
}
