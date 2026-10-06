<?php

namespace App\Domain\AI\Data;

/**
 * Demande de génération de texte, neutre vis-à-vis du fournisseur (ADR-011, ADR-043).
 * Aucune structure propre à un fournisseur : chaque AiProvider la traduit dans son format.
 */
final readonly class AiTextRequest
{
    public function __construct(
        public string $model,
        /** Contenu à traiter (texte de l'utilisateur, toujours transmis comme donnée). */
        public string $input,
        /** Consignes système (gabarit de prompt versionné, phase suivante). */
        public ?string $instructions = null,
        public int $maxOutputTokens = 256,
        /** null : valeur par défaut du modèle (certains modèles n'acceptent pas ce réglage). */
        public ?float $temperature = null,
    ) {}
}
