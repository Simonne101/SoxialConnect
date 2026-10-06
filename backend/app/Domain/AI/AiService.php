<?php

namespace App\Domain\AI;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiTextRequest;
use App\Domain\AI\Data\AiTextResult;
use App\Domain\AI\Enums\AiTask;
use App\Domain\AI\Exceptions\AiProviderException;
use App\Domain\AI\Providers\OpenAiProvider;
use Illuminate\Contracts\Container\Container;

/**
 * Point d'entrée IA de SoxialConnect (ADR-043) : choix du fournisseur et du modèle selon
 * config/ai.php. Le reste de l'application ne connaît que ce service et les DTO.
 *
 * Phase 1.5 : socle uniquement (fournisseur, modèle, vérification de connexion). Les générations
 * métier (idées, contenu, adaptation, script) et leur enregistrement dans ai_generations arrivent
 * dans une phase ultérieure.
 */
final class AiService
{
    /** Fournisseurs disponibles : nom dans config/ai.php → implémentation. */
    private const PROVIDERS = [
        OpenAiProvider::NAME => OpenAiProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    /** @throws AiProviderException fournisseur absent ou inconnu */
    public function provider(?string $name = null): AiProvider
    {
        $name ??= config('ai.default_provider');
        if (! is_string($name) || $name === '') {
            throw AiProviderException::notConfigured('AI_DEFAULT_PROVIDER non configuré.');
        }
        $class = self::PROVIDERS[$name] ?? null;
        if ($class === null) {
            throw AiProviderException::notConfigured("Fournisseur IA inconnu : {$name}.");
        }

        return $this->container->make($class);
    }

    /** Vrai si un fournisseur est choisi et que ses réglages (clé) sont présents. Aucun appel réseau. */
    public function isConfigured(): bool
    {
        try {
            return $this->provider()->isConfigured();
        } catch (AiProviderException) {
            return false;
        }
    }

    /** Modèle d'une tâche : AI_MODEL_<TÂCHE>, sinon AI_DEFAULT_MODEL (config/ai.php) ; null si aucun. */
    public function modelFor(AiTask $task): ?string
    {
        $model = config("ai.tasks.{$task->value}.model") ?? config('ai.default_model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Vérification minimale de la connexion au fournisseur par défaut : une requête très courte,
     * sans contenu utilisateur, sans aucune écriture en base.
     *
     * @throws AiProviderException
     */
    public function checkConnection(string $model): AiTextResult
    {
        return $this->provider()->generateText(new AiTextRequest(
            model: $model,
            input: 'Réponds uniquement par le mot OK.',
            // Minimum accepté par l'API ; les modèles de raisonnement peuvent l'épuiser avant de
            // répondre (réponse « incomplete ») : la connexion est tout de même établie.
            maxOutputTokens: 16,
        ));
    }
}
