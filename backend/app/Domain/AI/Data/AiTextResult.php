<?php

namespace App\Domain\AI\Data;

/**
 * Résultat normalisé d'une génération de texte (ADR-043) : ce que le reste de SoxialConnect
 * connaît d'une réponse IA. Ne contient ni clé, ni en-tête, ni réponse brute du fournisseur.
 */
final readonly class AiTextResult
{
    public function __construct(
        public string $provider,
        /** Modèle effectivement utilisé, tel que renvoyé par le fournisseur. */
        public string $model,
        public string $text,
        /** Réponse complète, ou tronquée (limite de tokens atteinte) : incompleteReason l'explique. */
        public bool $complete,
        public ?string $incompleteReason,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
        /** Identifiant de la réponse chez le fournisseur (diagnostic). */
        public ?string $responseId = null,
    ) {}
}
