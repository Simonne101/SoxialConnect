<?php

namespace App\Domain\AI\Contracts;

use App\Domain\AI\Data\AiImageRequest;
use App\Domain\AI\Data\AiImageResult;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTextResult;
use App\Domain\AI\Data\AiTranscriptionRequest;
use App\Domain\AI\Data\AiTranscriptionResult;
use App\Domain\AI\Enums\AiCapability;
use App\Domain\AI\Exceptions\AiProviderException;

/**
 * Fournisseur IA (ADR-011, ADR-043, ADR-046) : texte, transcription audio horodatée, image.
 * Toute particularité du fournisseur (URL, authentification, format de requête et de réponse,
 * codes d'erreur) reste dans son implémentation ; le reste de l'application ne voit que les DTO.
 */
interface AiProvider
{
    /** Nom du fournisseur dans config/ai.php (ex. « openai »). */
    public function name(): string;

    /** Vrai si les réglages nécessaires (clé API) sont présents ; aucun appel réseau. */
    public function isConfigured(): bool;

    /** Capacité réellement prise en charge par ce fournisseur (ADR-048). */
    public function supports(AiCapability $capability): bool;

    /** @throws AiProviderException */
    public function generateText(AiTextRequest $request): AiTextResult;

    /** @throws AiProviderException */
    public function transcribe(AiTranscriptionRequest $request): AiTranscriptionResult;

    /** @throws AiProviderException */
    public function generateImage(AiImageRequest $request): AiImageResult;
}
