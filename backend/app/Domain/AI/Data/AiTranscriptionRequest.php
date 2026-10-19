<?php

namespace App\Domain\AI\Data;

/** Transcription horodatée d'un fichier audio local (ADR-046). */
final readonly class AiTranscriptionRequest
{
    public function __construct(
        public string $model,
        /** Fichier audio local (jamais une URL) ; sa taille est bornée par config ai.transcription.max_file_mb. */
        public string $audioPath,
        /** Langue ISO 639-1 si connue (améliore la transcription), sinon détection automatique. */
        public ?string $language = null,
    ) {}
}
