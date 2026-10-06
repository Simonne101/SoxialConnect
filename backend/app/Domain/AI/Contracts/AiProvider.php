<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTextResult;
use App\Domain\AI\Exceptions\AiProviderException;

/**
 * Fournisseur IA (ADR-011, ADR-043). Volontairement minimal : génération de texte seulement.
 * Toute particularité du fournisseur (URL, authentification, format de requête et de réponse,
 * codes d'erreur) reste dans son implémentation ; le reste de l'application ne voit que les DTO.
 */
interface AiProvider
{
    /** Nom du fournisseur dans config/ai.php (ex. « openai »). */
    public function name(): string;

    /** Vrai si les réglages nécessaires (clé API) sont présents ; aucun appel réseau. */
    public function isConfigured(): bool;

    /** @throws AiProviderException */
    public function generateText(AiTextRequest $request): AiTextResult;
}
